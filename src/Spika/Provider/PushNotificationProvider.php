<?php

namespace Spika\Provider;

use Spika\Db\CouchDb;
use Spika\Db\MySql;
use Silex\Application;
use Silex\ServiceProviderInterface;

use Spika\BaiduPush\Channel;

define('SP_TIMEOUT',10);

class PushNotificationProvider implements ServiceProviderInterface
{
    public function register(Application $app)
    {
        
        $self = $this;
        
        $app['sendProdAPN'] = $app->protect(function($tokens,$payload) use ($self,$app) {
            $self->sendAPN($app['pushnotification.options']['APNProdPem'],$tokens,$payload,'ssl://gateway.push.apple.com:2195',$app);
        });
       
        $app['sendDevAPN'] = $app->protect(function($tokens,$payload) use ($self,$app) {           
            $self->sendAPN($app['pushnotification.options']['APNDevPem'],$tokens,$payload,'ssl://gateway.sandbox.push.apple.com:2195',$app);
        });

        $app['sendGCM'] = $app->protect(function($payload) use ($self,$app) {           
            $self->sendGCM($app['pushnotification.options']['GCMAPIKey'],$payload,$app);
        });

        $app['sendBaiduPush'] = $app->protect(function($payload,$tagname) use ($self,$app) {           
            $self->sendBaiduPush($app['pushnotification.options']['BAIDUAPIKEY'],$app['pushnotification.options']['BAIDUSECRETKEY'],$payload,$tagname,$app);
        });
        $app['setBaiduPushTag'] = $app->protect(function($tagname,$userid) use ($self,$app) {           
            $self->setBaiduPushTag($app['pushnotification.options']['BAIDUAPIKEY'],$app['pushnotification.options']['BAIDUSECRETKEY'],$tagname,$userid,$app);
        });
        $app['delBaiduPushTag'] = $app->protect(function($tagname,$userid) use ($self,$app) {           
            $self->delBaiduPushTag($app['pushnotification.options']['BAIDUAPIKEY'],$app['pushnotification.options']['BAIDUSECRETKEY'],$tagname,$userid,$app);
        });
    }

    public function boot(Application $app)
    {
    }
    
    public function connectToAPN($cert,$host,$app){
        
        $app['monolog']->addDebug("connecting to APN cert : {$cert}");
        
        $ctx = stream_context_create();
        stream_context_set_option($ctx, 'ssl', 'local_cert', $cert);
        $fp = stream_socket_client($host, $err, $errstr, 60, STREAM_CLIENT_CONNECT, $ctx);

        if (!$fp) {
            $app['monolog']->addDebug("Failed to connect $err $errstr");
            $app['monolog']->addDebug("Try to recconect");
            return $this->connectToAPN($cert,$host);
        }
        else {
            //stream_set_blocking($fp, 0);
            //stream_set_timeout($fp,SP_TIMEOUT);
        }
        
        return $fp;
    }
    
    public function sendAPN($cert, $deviceTokens, $payload, $host, $app){
                  
        $apn_status = array(
                        '0' => "No errors encountered",
                        '1' => "Processing error",
                        '2' => "Missing device token",
                        '3' => "Missing topic",
                        '4' => "Missing payload",
                        '5' => "Invalid token size",
                        '6' => "Invalid topic size",
                        '7' => "Invalid payload size",
                        '8' => "Invalid token",
                        '255' => "unknown"
                        );

        if(count($deviceTokens) == 0) return;
        
        $fp = $this->connectToAPN($cert,$host,$app);
        $size = 0;
        
        foreach($deviceTokens as $index => $deviceToken){
            
            $identifiers = array();
            for ($i = 0; $i < 4; $i++) {
                $identifiers[$i] = rand(1, 100);
            }
        
            $msg = chr(1) . chr($identifiers[0]) . chr($identifiers[1]) . chr($identifiers[2]) . chr($identifiers[3]) . pack('N', time() + 3600) 
                    . chr(0) . chr(32) . pack('H*', str_replace(' ', '', $deviceToken)) . pack("n",strlen($payload)) . $payload;
    
            $size += strlen($payload);
            
            $result = fwrite($fp, $msg);
            
            if($size >= 5120){
                // if sent more than 5120B reconnect again
                $fp = $this->connectToAPN($cert,$host,$app);
                sleep(1);
            }
            
            
            if(!$result){
                 $app['monolog']->addDebug("{$deviceToken} succeed");
                 
            }else{
                
                
                $read = array($fp);
                $null = null;
                $changedStreams = stream_select($read, $null, $null, 0, 1000000);
    
                if ($changedStreams === false) {    
                    $app['monolog']->addDebug("Error: Unabled to wait for a stream availability");
    
                } elseif ($changedStreams > 0) {
                    
                    $responseBinary = fread($fp, 6);
    
                    if ($responseBinary !== false || strlen($responseBinary) == 6) {
                        $response = unpack('Ccommand/Cstatus_code/Nidentifier', $responseBinary);
                        $result = $apn_status[$response['status_code']];
                        $app['monolog']->addDebug("{$deviceToken} failed {$result}");
                        
                    } else {
                        
                        $app['monolog']->addDebug("{$deviceToken} failed");
                        
                    }
                    
                } else {
                    
                    $result = 'succeed';
                    $app['monolog']->addDebug("{$deviceToken} succeed");
                    
                }
                
                if($result != 'succeed'){
                    // if failed connect again
                    $fp = $this->connectToAPN($cert,$host,$app);
                    sleep(1);
                }

    
            }

            
        }
        

        fclose($fp);

        return $result;

    }

   function sendGCM($apiKey, $json, $app = null) {
       
        $app['monolog']->addDebug($apiKey);

        // Set POST variables
        $url = 'https://android.googleapis.com/gcm/send';

        $headers = array( 
                        'Authorization: key=' . $apiKey,
                        'Content-Type: application/json'
                        );
        // Open connection
        $ch = curl_init();

        // Set the url, number of POST vars, POST data
        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_POSTFIELDS,$json);
        curl_setopt( $ch, CURLOPT_TIMEOUT,SP_TIMEOUT);

        // Execute post
        $result = curl_exec($ch);

        curl_close($ch);
        
        $app['monolog']->addDebug($result);


        return $result;

    }

    function sendBaiduPush($apiKey,$secretKey,$messageContent,$tagname,$app = null){
        $channel = new Channel ($apiKey, $secretKey) ;
        //推送消息到某个user，设置push_type = 1; 
        //推送消息到一个tag中的全部user，设置push_type = 2;
        //推送消息到该app中的全部user，设置push_type = 3;
        $push_type = 1; //推送单播消息
        $app['monolog']->addDebug('message Conten: ' .  $messageContent['userid'][0]);
        if(empty($tagname))
            $optional[Channel::USER_ID] = $messageContent['userid'][0]; //如果推送单播消息，需要指定user
        else {
            $push_type = 2;
            $optional[Channel::TAG_NAME] = $tagname;  //如果推送tag消息，需要指定tag_name
        }

        //指定发到android设备
        $optional[Channel::DEVICE_TYPE] = 3;
        //指定消息类型为通知
        $optional[Channel::MESSAGE_TYPE] = 0;
        //通知类型的内容必须按指定内容发送，示例如下：
        $fields = array(
            'title'                     => "",
            'notification_basic_style'  =>5,
            'open_type'         =>2,
            'description'               =>$messageContent['description'],//"新消息",
            'custom_content'        => $messageContent['content'],
        );


        $message = json_encode($fields);
        
        $message_key = "msg_key";
        $app['monolog']->addDebug("before hjf: " . $apiKey . " " . $secretKey);
        $ret = $channel->pushMessage ( $push_type, $message, $message_key, $optional) ;
        if ( false === $ret )
        {
            $app['monolog']->addDebug('ERROR NUMBER: ' . $channel->errno() . ' ERROR MESSAGE: ' . $channel->errmsg());
        }
        else
        {
            $app['monolog']->addDebug('SUCC, ' . __FUNCTION__ . ' OK!!!!! result:' . print_r($ret, true));
        }
        return $ret;
    }

    function setBaiduPushTag($apiKey,$secretKey,$tagname,$userid,$app = null){
        $channel = new Channel ($apiKey, $secretKey) ;

        $app['monolog']->addDebug('set tag: ' .  $tagname);
        $optional=null;
        if(!empty($userid))
            $optional[Channel::USER_ID] = $userid;
        $ret = $channel->setTag($tagname, $optional);

        
        if ( false === $ret )
        {
            $app['monolog']->addDebug('ERROR NUMBER: ' . __FUNCTION__ . $channel->errno() . ' ERROR MESSAGE: ' . $channel->errmsg());
        }
        else
        {
            $app['monolog']->addDebug('SUCC, ' . __FUNCTION__ . ' OK!!!!! result:' . print_r($ret, true));
        }
        return $ret;
    }
    
    function delBaiduPushTag($apiKey,$secretKey,$tagname,$userid,$app = null){
        $channel = new Channel ($apiKey, $secretKey) ;

        $app['monolog']->addDebug('set tag: ' .  $tagname);
        $optional=null;
        if(!empty($userid))
            $optional[Channel::USER_ID] = $userid;
        $ret = $channel->deleteTag($tagname, $optional);

        
        if ( false === $ret )
        {
            $app['monolog']->addDebug('ERROR NUMBER: ' . __FUNCTION__ . $channel->errno() . ' ERROR MESSAGE: ' . $channel->errmsg());
        }
        else
        {
            $app['monolog']->addDebug('SUCC, ' . __FUNCTION__ . ' OK!!!!! result:' . print_r($ret, true));
        }
        return $ret;
    }
    
}
