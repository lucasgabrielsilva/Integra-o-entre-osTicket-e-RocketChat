<?php

require('client.inc.php');
require_once(INCLUDE_DIR.'class.file.php');
require_once(INCLUDE_DIR.'class.http.php');



switch(filter_input(INPUT_SERVER, 'REQUEST_METHOD')){
    case "GET":
            $params = filter_input_array(INPUT_GET);
            if((isset($params['key'])) && (isset($params['signature'])) && (isset($params['expires'])) && ($file = AttachmentFile::lookupByHash($params['key']))){
                try{
                    $file->download($file->getName(), False, $params['expires']);
                } catch(Exception $ex){
                    Http::response(500, var_export($ex, True));
                }
            }
            break;
    default:
            Http::response(405, 'Incorret method');
}

?>
