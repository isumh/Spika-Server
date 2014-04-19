<?php

/*
 * This file is part of the Silex framework.
 *
 * Copyright (c) 2013 clover studio official account
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Spika\Controller;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ParameterBag;


class FileController extends SpikaBaseController
{

    static $paramName = 'file';
    static $fileDirName = 'uploads';
        
    public function connect(Application $app)
    {
        global $beforeTokenCheker;
        
        $controllers = $app['controllers_factory'];
        $self = $this;
        
        // ToDo: Add token check
        $controllers->get('/filedownloader', function (Request $request) use ($app,$self) {
                
            $fileID = $request->get('file');
            $filePath = __DIR__.'/../../../'.FileController::$fileDirName."/".basename($fileID);
            $app['monolog']->addDebug('hjf file cache0 '.$filePath);
            if(file_exists($filePath)){
                $app['monolog']->addDebug('hjf file cache 1');
                    $response->setPublic();
                    $response->setLastModified(filemtime($filePath));
                    if ($response->isNotModified($request)) {
                        $app['monolog']->addDebug('hjf file cache 2');
                        return $response;
                    }
    
                    $response = $app->sendFile($filePath);
                    $response->setETag(md5($response->getContent()));
                    $app['monolog']->addDebug('hjf new file');
                    return $response;
                    
            }else{
                    return $self->returnErrorResponse("file doesn't exists.");
            }
        });
                
        //})->before($app['beforeTokenChecker']);
        
        // ToDo: Add token check
        $controllers->post('/fileuploader', function (Request $request) use ($app,$self) {
                
            $file = $request->files->get(FileController::$paramName); 
            $fineName = \Spika\Utils::randString(20, 20) . time();
            
            if(!is_writable(__DIR__.'/../../../'.FileController::$fileDirName))
                    return $self->returnErrorResponse(FileController::$fileDirName ." dir is not writable.");
                    
            $file->move(__DIR__.'/../../../'.FileController::$fileDirName, $fineName); 
            return $fineName; 
                                
        })->before($app['beforeApiGeneral']);
        
        //})->before($app['beforeTokenChecker']);
        
        return $controllers;
    }

}

?>
