<?php
/*********************************************************************
    http.php

    HTTP controller for the osTicket API

    Jared Hancock
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
// Use sessions — it's important for SSO authentication, which uses
// /api/auth/ext
define('DISABLE_SESSION', false);

require 'api.inc.php';

# Include the main api urls
require_once INCLUDE_DIR."class.dispatcher.php";
require_once INCLUDE_DIR."externalChatController.php";
require_once INCLUDE_DIR."class.http.php";

$api = new ApiController;
$api->requireApiKey();
unset($api);

switch(filter_input(INPUT_SERVER, 'REQUEST_METHOD')){
        case "GET":
                $params = filter_input_array(INPUT_GET);
                break;
        case "POST":
                $params = filter_input_array(INPUT_POST);
                break;
        default:
                Http::response(405, 'Incorret method');
}

$exceptions = array('createTableStaffGroup', 
        'createTableBots', 
        'createTicket', 
        'getDataTableStaffGroup', 
        'getDataTableBots', 
        'getTicketStatus',
	'teste');

if((isset($params['method'])) && (method_exists('externalChatController', $params['method']))){
        if(in_array($params['method'], $exceptions)){
                call_user_func('externalChatController::'.$params['method']);
        }
        else{
                call_user_func('externalChatController::'.$params['method'], $params);
        }
}
elseif($ost->get_path_info()){
	ExternalChatController::createTicket($ost->get_path_info());

}
else{
        Http::response(406, 'Incorret parameters');               
}

?>


