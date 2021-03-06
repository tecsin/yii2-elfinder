<?php

/**
 * @copyright Copyright (c) 2013-2017 Sajflow Services
 * @link https://www.sajflow.com
 * @license http://www.opensource.org/licenses/bsd-license.php New BSD License
 */

namespace tecsin\elfinder;

use Yii;
use \elFinder;
use \elFinderConnector;
use yii\web\Response;
use yii\base\InvalidConfigException;
use tecsin\elfinder\CachedStorageAdapter;
use League\Flysystem\Cached\CachedAdapter;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Hypweb\Flysystem\GoogleDrive\GoogleDriveAdapter;
use \Google_Client;
use \Google_Service_Drive;

/**
 * FileManager class with support for local file system, ftp, and google drive by default.
 *
 * @author Samuel Onyijne <samuel@sajflow.com>
 */
Class FileManager {
    public  $debug = false; 
    public  $ftpUser = '';
    public  $ftpPassword = '';
    public  $ftpDir = '/';
    public  $ftpHost = 'localhost';
    public  $ftpTimeOut = 10;
    public  $_uploadPath;
    public  $_uploadUrl;
    
    public $googleDrive = [];


    private function access($attr, $path, $data, $volume) {
	return strpos(basename($path), '.') === 0       // if file/folder begins with '.' (dot)
		? !($attr == 'read' || $attr == 'write')    // set read+write to false, other (locked+hidden) set to true
		:  null;                                    // else elFinder decide it itself
    }
     
    /**
     * call the connector method in an action specfied as the connector url for file manager. 
     * You can call your options inside the action calling this method before calling this method.
     */
    public function connector() {
        // Enable FTP connector netmount
         elFinder::$netDrivers['ftp'] = 'FTP';
         $this->access('write', '/', '', '');
        
         $opts = [
	    'debug' => $this->debug,
	    'roots' => $this->volumeRoots()
         ];
         Yii::$app->response->format = Response::FORMAT_JSON;
         $connector = new elFinderConnector(new elFinder($opts));
         $connector->run();   
    }
    
    /**
     * 
     * @return array the volumn roots to use in options. override this methos to change roots settings
     */
    public function volumeRoots(){
        $roots = [
		    [
			'driver'        => 'LocalFileSystem',           // driver for accessing file system (REQUIRED)
			'path'          => $this->_uploadPath, // path to files (REQUIRED)
			'URL'           => $this->_uploadUrl, // URL to files (REQUIRED)
			//'uploadDeny'    => array('all'),                // All Mimetypes not allowed to upload
			//'uploadAllow'   => array('image', 'text/plain'),// Mimetype `image` and `text/plain` allowed to upload
			//'uploadOrder'   => array('deny', 'allow'),      // allowed Mimetype `image` and `text/plain` only
			'accessControl' => 'access'                     // disable and hide dot starting files (OPTIONAL)
		    ],
                    [
                        'driver'        => 'FTP',
                        'host'          => $this->ftpHost,
                        'user'          => $this->ftpUser,
                        'pass'          => $this->ftpPassword,
                        'port'          => 21,
                        'mode'          => 'passive',
                        'path'          => $this->ftpDir,
                        'timeout'       => $this->ftpTimeOut,
                        'owner'         => true,
                        'dirMode'       => 0755,
                        'fileMode'      => 0644
                    ],
                    
	];
        if($this->useGoogleDrive()){
            $array = $this->googleDrive();
            $roots[][] = [
                        // require
                        'driver'       => 'FlysystemExt',
                        'filesystem'   =>  new Filesystem($array['adapter']),
                        'fscache'      => $array['cache'],
                        'separator'    => '/',
                         // optional
                        'alias'        => 'GoogleDrive',
                        'rootCssClass' => 'elfinder-navbar-root-googledrive'
                     ];
        }
        return $roots;
    }
    
    public function setUploadPath($path){
        return $this->_uploadPath = (isset($path))? $path : Yii::getAlias('@webroot').'/uploads/';
    }
    
    public function getUploadPath(){
        return $this->_uploadPath;
    }

    public function setUploadUrl($url){
        return $this->_uploadUrl = (isset($url))? $url : Yii::getAlias('@web').'/uploads/';
    }
    
    public function getUploadUrl(){
        return $this->_uploadUrl;
    }
    
    
    private function useGoogleDrive()
    {
        if(empty($this->googleDrive)){
            return false;
        }
        if(empty($this->googleDrive['clientID'])){
            throw new InvalidConfigException('clientID no set in googleDrive config options');
        }
        if(empty($this->googleDrive['clientSecret'])){
            throw new InvalidConfigException('clientSecret no set in googleDrive config options');
        }
        if(empty($this->googleDrive['refreshToken'])){
            throw new InvalidConfigException('refreshToken no set in googleDrive config options');
        }
        return true;
    }
    
    /**
     * 
     * @return array containing cache and adapter
     */
    private function googleDrive()
    {
        // Google API Client
        $client = new Google_Client();
        $client->setClientId($this->googleDrive['clientID']);
        $client->setClientSecret($this->googleDrive['clientSecret']);
        $client->refreshToken($this->googleDrive['refreshToken']);

        // Google Drive Adapter
        $googleDrive = new GoogleDriveAdapter(
            new Google_Service_Drive($client), // Client service
            'root',                             // Holder ID as root ('root' or Holder ID)
            [ 'useHasDir' => true ]             // options (elFinder need hasDir method)
        );
         // Make Flysystem adapter and cache object
        if(isset($this->googleDrive['useCache'])){
            $useCache = (is_bool($this->googleDrive['useCache'])) ? $this->googleDrive['useCache']: true;
        } else {
            $useCache = true;
        }
        if ($useCache) {
            // Example to Flysystem cacheing
            $cache = new CachedStorageAdapter(
                new Local('flycache'),
                'gdcache',
                300
            );

            // Flysystem cached adapter
            $adapter = new CachedAdapter(
                $googleDrive,
                $cache
            );
        } else {
            // Not use cached adapter
            $cache = null;
            $adapter = $googleDrive;
        }
        return [
            'cache' => $cache,
            'adapter' => $adapter
        ];
    }
}
