<?php

include_once(INCLUDE_DIR.'class.api.php');
include_once(INCLUDE_DIR.'class.user.php');
include_once(INCLUDE_DIR.'class.client.php');
include_once(INCLUDE_DIR.'class.thread.php');
include_once(INCLUDE_DIR.'class.ticket.php');
include_once(INCLUDE_DIR.'class.file.php');
include_once(INCLUDE_DIR.'class.http.php');
include_once(INCLUDE_DIR.'class.forms.php');
include_once(INCLUDE_DIR.'class.dispatcher.php');
//include_once(INCLUDE_DIR.'class.signal.php');

class ExternalChatController {

    function getTicketStatus(){
        $sql = "select dbticketstatus.id, dbticketstatus.name 
            from ost_ticket_status as dbticketstatus;";
        try{
            $res = db_query($sql);
            $res = db_assoc_array($res);
            Http::response(200, json_encode($res));
        } catch(Exception $ex){
            Http::response(500, var_export($ex, True));
        }
    }

    function getFilesUrls($ticketnumber){
        $files = array();
        $sql = "select dbthreadentry.id 
            from ost_ticket as dbticket, ost_thread as dbthread, ost_thread_entry as dbthreadentry 
            where dbticket.number = ".$ticketnumber." and dbticket.ticket_id = dbthread.object_id and dbthread.object_type = 'T' and dbthreadentry.thread_id = dbthread.id and (dbthreadentry.type = 'M' or dbthreadentry.type = 'R');";
        try{
            $res = db_query($sql);
            $res = db_assoc_array($res);
        } catch(Exception $ex){
            Http::response(500, var_export($ex, True));
        }
        foreach($res as $num){
            $data = ThreadEntry::lookup($num['id'])->getAttachmentUrlsForChat();
            if($data){
                for($i = 0; $i < count($data); $i++){
                    array_push($files, $data[$i]);
                }
            }
        }
	return $files;
    }

     function sqlQueryForTicketData($ticketnumbr){
        $ticketnumber = 360274;
	$num = 5;
        $sqlDatas = "select number as numero, subject as titulo, dbticket.created as criado_em, lastresponse as ultima_resposta, lastmessage as ultima_messagem, name as status
            from ost_ticket as dbticket, ost_ticket__cdata as dbticketcdata, ost_thread as dbthread, ost_ticket_status as dbstatus
            where dbticket.number = ".$ticketnumber." and dbticket.ticket_id = dbticketcdata.ticket_id and dbticket.ticket_id = dbthread.object_id and dbthread.object_type = 'T' and dbticket.status_id = dbstatus.id;";
            
        $sqlCollaborators = "select name as nome, dbthreadcollaborator.created as adicionado_em 
            from ost_ticket as dbticket, ost_thread as dbthread, ost_thread_collaborator as dbthreadcollaborator, ost_user as dbuser 
            where dbticket.number = ".$ticketnumber." and dbticket.ticket_id = dbthread.object_id and dbthread.object_type = 'T' and dbthreadcollaborator.thread_id = dbthread.id and dbthreadcollaborator.user_id = dbuser.id;";
            
        $sqlMessages = "select dbthreadentry.poster, dbthreadentry.created, dbthreadentry.body 
            from ost_ticket as dbticket, ost_thread as dbthread, ost_thread_entry as dbthreadentry 
            where dbticket.number = ".$ticketnumber." and dbthread.id = dbticket.ticket_id and  dbthread.object_type = 'T' and dbthreadentry.thread_id = dbthread.id and (dbthreadentry.type = 'M' or dbthreadentry.type = 'R');";         

        $sqlNotes = "select dbthreadentry.poster, dbthreadentry.created, dbthreadentry.body 
            from ost_ticket as dbticket, ost_thread as dbthread, ost_thread_entry as dbthreadentry 
            where dbticket.number = ".$ticketnumber." and dbthread.id = dbticket.ticket_id and  dbthread.object_type = 'T' and dbthreadentry.thread_id = dbthread.id and dbthreadentry.type = 'N';";

        $sqlTasks = "select dbtaskcdata.title, dbtask.created, dbtask.closed 
            from ost_ticket as dbticket, ost_task as dbtask, ost_task__cdata as dbtaskcdata 
            where dbticket.number = ".$ticketnumber." and dbtask.object_id = dbticket.ticket_id and dbtask.id = dbtaskcdata.task_id;";

        $sql = array($sqlDatas, $sqlCollaborators, $sqlMessages, $sqlNotes, $sqlTasks);
        $infos = array();
        try{
            for($i = 0; $i < $num; $i++){
                $res = db_query($sql[$i]);
                $res = db_assoc_array($res);
                switch($i){
                    case 0:
                        $infos['datas'] = $res;
                        break;
                    case 1:
                        $infos['collaborators'] = $res;
                        break;
                    case 2:
                        $infos['messages'] = $res;
                        break;
                    case 3:
                        $infos['notes'] = $res;
                        break;
                    case 4:
                        $infos['tasks'] = $res;
                        break;
                }
            }
	    if($num > 3){
                for($i = 0; $i < count($infos['notes']); $i++){
                    $infos['notes'][$i]['body'] = self::parseMessageForChat($infos['notes'][$i]['body']);
                }
            }
            for($i = 0; $i < count($infos['messages']); $i++){
                $infos['messages'][$i]['body'] = self::parseMessageForChat($infos['messages'][$i]['body']);
            }
        } catch(Exception $ex){
            Http::response(500, var_export($ex, True));
        }
        return $infos;
    }

    function uploadFiles(){
        $files = array();
        $form = new FileUploadField;
        foreach($_FILES as $f){
            if($TempFile = $form->uploadFile($f)){
                array_push($files, array('name' => $TempFile->name,
                    'id' => $TempFile->id));
            }
        }
        if($files){
            return $files;
        }
        else{
            return False;
        }
    }

    function createTicket($path){
        $dispatcher = patterns('', url_post("^/tickets\.(?P<format>xml|json|email)$", array('api.tickets.php:ticketapicontroller','create')),
        url('^/tasks/', patterns('',url_post("^cron$", array('api.cron.php:CronApiController', 'execute')))));
        Signal::send('api', $dispatcher);
        print $dispatcher->resolve($path);
    }

    function getTicketsOpenByUser($params){
        if((!isset($params['requesteremail'])) || (!isset($params['clientemail']))){
            Http::response(406, 'Invalid parameters');
        }
        elseif(!User::lookupByEmail($params['clientemail'])){
            Http::response(403, 'User request not exists');
        }
        elseif((!$staff = Staff::lookup($params['requesteremail'])) && ($params['requesteremail'] != $params['clientemail'])){
            Http::response(403,'Not staff or not ticket owner');
        }
        else{
            $sql = "select number as numero, subject as titulo, dbticket.created as criado_em, lastresponse as ultima_resposta, lastmessage as ultima_messagem, name as status
                from ost_ticket as dbticket, ost_ticket__cdata as dbcdata, ost_thread as dbthread, ost_ticket_status as dbstatus
                where dbticket.ticket_id = dbcdata.ticket_id and dbticket.ticket_id = dbthread.object_id and dbthread.object_type = 'T' and dbticket.status_id = dbstatus.id and dbticket.user_id =
                (select user_id from ost_user_email where address = '".$params['clientemail']."');";
            try{
                $res = db_query($sql);
                $res = db_assoc_array($res);
                Http::response(200, json_encode($res));
            } catch(Exception $ex){
                Http::response(500, var_export($ex, True));
            }
        }
    }

    function getAllTicketsOpen($params){
        if(!isset($params['requesteremail'])){
            Http::response(406, 'Invalid parameters');
        }
        elseif(!$staff = Staff::lookup($_REQUEST['requesteremail'])){
            Http::response(403,'Not staff');
        }
        else {
            $sql = "select dbticket.number as numero, dbuser.name as criador, dbticketcdata.subject as titulo, dbticket.created as criado_em, dbthread.lastresponse as ultima_resposta, dbthread.lastmessage as ultima_messagem, dbticketstatus.name as status 
                from ost_ticket as dbticket, ost_user as dbuser, ost_ticket__cdata as dbticketcdata, ost_thread as dbthread, ost_ticket_status as dbticketstatus 
                where dbticket.ticket_id = dbticketcdata.ticket_id and dbticket.user_id = dbuser.id and dbticket.ticket_id = dbthread.object_id and dbthread.object_type = 'T' and dbticket.status_id = dbticketstatus.id and dbticket.closed IS NULL;";
            try{
                $res = db_query($sql);
                $res = db_assoc_array($res);
                Http::response(200, json_encode($res));
            } catch(Exception $ex){
                Http::response(500, var_export($ex, True));
            }
        }
    }

    function getTicketData($params){
        if((!isset($params['requesteremail'])) || (!isset($params['ticketnumber']))){
            Http::response(406, 'Invalid parameters');
        }
        elseif(!$ticket = Ticket::lookupByNumber($params['ticketnumber'])){
            Http::response(403, 'Ticket number not exists');
        }
        elseif($ticket->checkStaffPerm(Staff::lookup($params['requesteremail']))){
            $num = 5;
        }
        elseif($ticket->checkUserAccess(User::lookupByEmail($params['requesteremail']))){
            $num = 3;
        }
        else{
            Http::response(401, 'Not have acess to this ticket');
        }
        $infos = self::sqlQueryForTicketData($params['ticketnumber'], $num);
        $files = self::getFilesUrls($params['ticketnumber']);
        $infos['files'] = $files;
        Http::response(200, json_encode($infos));
    }

    function addCollaboratorToTicket($params){
        if((!isset($params['requesteremail'])) || (!isset($params['clientemail'])) || (!isset($params['ticketnumber']))){
            Http::response(406, 'Invalid parameters');
        }
        elseif((!$staff = Staff::lookup($params['requesteremail'])) || (!$collaborator = User::lookupByEmail($params['clientemail'])) || (!$ticket =Ticket::lookupByNumber($params['ticketnumber']))){
            Http::response(500, 'Not staff or collaborator not exists');
        }
        else{
            $settings = array('isactive' => True);
            if($msg = $ticket->addCollaborator($collaborator, $settings, $error)){
                Http::response(201, "Collaborator add");
            }
            else{
                Http::response(500, "Add collaborator is not possible");
            }
        }
    }

    function messageToTicket($params){
        if((!isset($params['requesteremail'])) || (!isset($params['ticketnumber'])) || (!isset($params['message']))){
            Http::response(406, 'Invalid parameters');
        }
        elseif((!$ticket = Ticket::lookupByNumber($params['ticketnumber'])) || (!$user = User::lookupByEmail($params['requesteremail']))){
            Http::response(403, 'invalid ticket number or invalid email user');
        }
        elseif(!$ticket->checkUserAccess(new EndUser($user))){
            Http::response(403, 'You not have access to this ticket');
        }
        elseif($_FILES){
            if(!$files = self::uploadFiles()){
                Http::response(500, 'Add files not possible, try again');
            }
        }
        $ccs = array();
        foreach($ticket->getCollaborators() as $collaborator){
            array_push($ccs, $collaborator->getUserId());
        }
        $datas = array('userId' => $user->getId(),
            'poster' => $user->getName(),
            'ccs' => $ccs,
            'message' => $params['message']);
	if(isset($files)){
            $datas['files'] = $files;
        }
        if($msg = $ticket->postMessage($datas, 'API')){
            Http::response(201, 'Message posted');
        }
        else{
            Http::response(500, 'Error - message not posted');
        }
    }

     function replyToTicket($params){
        if((!isset($params['requesteremail'])) || (!isset($params['ticketnumber'])) || (!isset($params['reply']))){
            Http::response(406, 'Invalid parameters');
        }
        elseif((!$ticket = Ticket::lookupByNumber($params['ticketnumber'])) || (!$staff = Staff::lookup($params['requesteremail']))){
            Http::response(403, 'invalid ticket number or invalid email user');
        }
        elseif(!$ticket->checkStaffPerm($staff)){
            Http::response(403, 'You not have access to this ticket');
        }
	elseif($_FILES){
            if(!$files = self::uploadFiles()){
                Http::response(500, 'Add files not possible, try again');
            }
        }
        $ccs = array();
        foreach($ticket->getCollaborators() as $collaborator){
            array_push($ccs, $collaborator->getUserId());
        }
        $datas = array('poster' => $staff,
            'staffId' => $staff->getId(),
            'ccs' => $ccs,
            'reply-to' => 'all',
            'from-email-id' => '1',
            'a' => 'reply',
            'response' => $params['reply']);
	if(isset($files)){
            $datas['files'] = $files;
        }
        if($msg = $ticket->postReply($datas, $erro, 'all')){
            Http::response(201, 'Response posted');
        }
        else{
            Http::response(500, 'Error - Response not posted');
        }
    }

     function addNoteToTicket($params){
        if((!isset($params['requesteremail'])) || (!isset($params['ticketnumber'])) || (!isset($params['note']))){
            Http::response(406, 'Invalid parameters');
        }
        elseif((!$ticket = Ticket::lookupByNumber($params['ticketnumber'])) || (!$staff = Staff::lookup($params['requesteremail']))){
            Http::response(403, 'invalid ticket number or invalid email user');
        }
        elseif(!$ticket->checkStaffPerm($staff)){
            Http::response(403, 'You not have access to this ticket');
        }
	elseif($_FILES){
            if(!$files = self::uploadFiles()){
                Http::response(500, 'Add files not possible, try again');
            }
        }
        $datas = array('staffId' => $staff->getId(),
            'note' => $params['note']);
	if(isset($files)){
            $datas['files'] = $files;
        }
        $poster = $staff->getName()->name;
        if($msg = $ticket->postNote($datas, $erro, $poster)){
            Http::response(201, 'Note posted');
        }
        else{
            Http::response(500, 'Error - Note not posted');
        }
    }

     function changeTicketStatus($params){
        if((!isset($params['requesteremail'])) || (!isset($params['ticketnumber'])) || (!isset($params['idstatus']))){
            Http::response(406, 'Invalid parameters');
        }
        elseif((!$ticket = Ticket::lookupByNumber($params['ticketnumber'])) || (!$staff = Staff::lookup($params['requesteremail']))){
            Http::response(403, 'invalid ticket number or invalid email user');
        }
        elseif(!$ticket->checkStaffPerm($staff)){
            Http::response(403, 'You not have access to this ticket');
        }
        elseif($ticket->setStatusForChat($params['idstatus'], $staff, $params['comment'])){
            Http::response(201, 'Ticket status changed');
        }
        else{
            Http::response(500, 'Ticket status not changed');
        }
    }

     function createTableStaffGroup(){
        $sql = " create table IF NOT EXISTS chat_staff_group (
            id int NOT NULL AUTO_INCREMENT, 
            group_name varchar(255) NOT NULL UNIQUE, 
            group_id varchar(255) NOT NULL UNIQUE, 
            PRIMARY KEY (id));";
        try{
            $res = db_query($sql);
            Http::response(201, var_export($res, True));
        } catch(Exception $ex){
            Http::response(500, var_export($ex, True));
        }
    }

    function createTableBots(){
        $sql = " create table IF NOT EXISTS chat_bots (
            id int NOT NULL AUTO_INCREMENT, 
            bot_name varchar(255) NOT NULL UNIQUE, 
            bot_id varchar(255) NOT NULL UNIQUE,
            bot_auth_token varchar(255) NOT NULL UNIQUE,
            bot_password varchar(255) NOT NULL,
            isStaff Boolean NOT NULL UNIQUE, 
            PRIMARY KEY (id));";
        try{
            $res = db_query($sql);
            Http::response(201, var_export($res, True));
        } catch(Exception $ex){
            Http::response(500, var_export($ex, True));
        }
    }

    function insertDataTableStaffGroup($params){
        $sql = "select count(id) from chat_staff_group;";
        $res = db_query($sql);
        if(db_result($res) >= 1){
            Http::response(500, "table already have group!");
        }
        else{
            $sql = "insert into chat_staff_group (
                group_name, group_id) 
                values('".$params['groupName']."',
                '".$params['groupId']."');";
            try{
                $res = db_query($sql);
                Http::response(201, var_export($res, True));
            } catch(Exception $ex){
                Http::response(500, var_export($ex, True));
            }
        }
    }

    function insertDataTableBots($params){
        $sql = "select count(id) from chat_bots;";
        $res = db_query($sql);
        if(db_result($res) >= 2){
            Http::response(500, "table already have bots!");
        }
        else{
            $sql = "insert into chat_bots (
                bot_name, bot_id, bot_auth_token, bot_password, isStaff) 
                values('".$params['botName']."',
                '".$params['botId']."',
                '".$params['botAuthToken']."',
                '".base64_encode($params['botPassword'])."',
                ".$params['isStaff'].");";
            try{
                $res = db_query($sql);
                Http::response(201, var_export($res, True));
            } catch(Exception $ex){
                Http::response(500, var_export($ex, True));
            }
        }
    }

    function getDataTableStaffGroup(){
        $sql = "select * from chat_staff_group;";
        try{
            $res = db_query($sql);
            $res = db_assoc_array($res);
            return $res[0];
        } catch(Exception $ex){
            return False;
        }
    }

    function getDataTableBots($isStaff){
        if($isStaff){
            $sql = "select * 
                from chat_bots
                where isStaff = 1";
        }
        else{
            $sql = "select * 
                from chat_bots
                where isStaff = 0";
        }
        try{
            $res = db_query($sql);
            $res = db_assoc_array($res);
            return $res[0];
        } catch(Exception $ex){
            return False;
        }
    }

    function editTableStaffGroup($params){
        $sql = "update chat_staff_group
            SET group_name = '".$params['groupName']."', 
            group_id = '".$params['groupId']."'
            WHERE group_name = ".$params['oldGroupName'].";";
        try{
            $res = db_query($sql);
            Http::response(200, 'Table updated!');
        } catch (Exception $Ex){
            Http::response(500, var_export($ex, True));
        }
    }

    function editTableBots($params){
        $sql = "update chat_bots
            set bot_name = '".$params['botName']."', 
            bot_id = '".$params['botId']."',
            bot_auth_token = '".$params['botAuthToken']."',
            bot_password = '".base64_encode($params['botPassword'])."'
            WHERE isStaff = ".$params['isStaff'].";";
        try{
            $res = db_query($sql);
            Http::response(200, 'Table updated!');
        } catch (Exception $Ex){
            Http::response(500, var_export($ex, True));
        }
    }

    function makeRequestChat($params){
        try{
            $call = curl_init();
            curl_setopt($call, CURLOPT_URL, '192.168.56.101:3000/api/v1/'.$params['url']);
            curl_setopt($call, CURLOPT_POST, 1);
            curl_setopt($call, CURLOPT_POSTFIELDS, json_encode($params['data']));
            curl_setopt($call, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($call, CURLOPT_FOLLOWLOCATION, FALSE);
            curl_setopt($call, CURLOPT_HEADER, FALSE);
            curl_setopt($call, CURLOPT_HTTPHEADER, $params['header']);
            $msg = curl_exec($call);
            curl_close($call);
            return json_decode($msg);
        } catch(Exception $ex){
            return $ex;
        }
    }

    function getAttachmentsData($id){
        $files = ThreadEntry::lookup($id)->getAttachmentUrlsForChat();
        if($files){
            $msg = "";
            foreach($files as $f){
                $msg .= $f['name'].": ".$f['url']."\n";
	    }
            return $msg;
        }
        else{
            return "Não foi enviado anexo(s)";
        }
    }

    function parseMessageForChat($msg){
        $msg = str_replace("</p>", "\n", $msg);
	$msg = strip_tags($msg);
    	return $msg;
    }

    function prepareMessageForChat($ticket, $type, $id, $extra=False){
        switch($type){
            case "C":
                $msg = "Um novo chamado foi aberto!\n
                    Proprietario: ".$ticket->getOwner()->getName()->name."
                    E-mail: ".$ticket->getOwner()->getEmail()."
                    Data de criação: ".$ticket->getCreateDate()."
                    Número: ".$ticket->getNumber()."
                    Titulo do chamado: ".$ticket->getSubject()."
                    Anexo(s):\n".self::getAttachmentsData($id)."
                    Descrição:\n".self::parseMessageForChat($ticket->getLastMessage());
                $roomId = self::getDataTableStaffGroup()['group_id'];
                break;
            case "M":
                $msg = "Um chamado recebeu uma nova mensagem!\n
                    Proprietario: ".$ticket->getOwner()->getName()->name."
                    E-mail: ".$ticket->getOwner()->getEmail()."
                    Data de criação: ".$ticket->getCreateDate()."
                    Número: ".$ticket->getNumber()."
                    Título do chamado: ".$ticket->getSubject()."
                    Status atual do chamado: ".$ticket->getStatus()."
                    Criador da messagem: ".$ticket->getLastUserRespondent()->getName()->name."
                    Data da postagem: ".$ticket->getLastMessageDate()."
                    Anexo(s):\n".self::getAttachmentsData($id)."
                    Mensagem:\n".self::parseMessageForChat($ticket->getLastMessage());
                $roomId = self::getDataTableStaffGroup()['group_id'];
                break;
            case "MU":
                $msg = "Um dos seus chamados recebeu uma nova mensagem!\n
                    Proprietario: ".$ticket->getOwner()->getName()->name."
                    E-mail: ".$ticket->getOwner()->getEmail()."
                    Data de criação: ".$ticket->getCreateDate()."
                    Número: ".$ticket->getNumber()."
                    Título do chamado: ".$ticket->getSubject()."
                    Status atual do chamado: ".$ticket->getStatus()."
                    Criador da messagem: ".$ticket->getLastUserRespondent()->getName()->name."
                    Data da postagem: ".$ticket->getLastMessageDate()."
                    Anexo(s):\n".self::getAttachmentsData($id)."
                    Mensagem:\n".self::parseMessageForChat($ticket->getLastMessage());
                $poster = $ticket->getLastUserRespondent()->getUserNameForChat();
                $bot = self::getDataTableBots(False);
                break;
            case "R":
                $msg = "Um chamado foi respondido\n
                    Proprietario: ".$ticket->getOwner()->getName()->name."
                    E-mail: ".$ticket->getOwner()->getEmail()."
                    Data de criação: ".$ticket->getCreateDate()."
                    Número: ".$ticket->getNumber()."
                    Título do chamado: ".$ticket->getSubject()."
                    Status atual do chamado: ".$ticket->getStatus()."
                    Criador da resposta: ".$ticket->getLastRespondent()->getName()->name."
                    Data da postagem: ".$ticket->getLastResponseDate()."
                    Anexo(s):\n".self::getAttachmentsData($id)."
                    Resposta:\n".self::parseMessageForChat($extra);
                $roomId = self::getDataTableStaffGroup()['group_id'];
                break;
            case "RU":
                $msg = "Um dos seus chamados foi respondido!\n
                    Título do chamado: ".$ticket->getSubject()."
                    Data de criação: ".$ticket->getCreateDate()."
                    Número: ".$ticket->getNumber()."
                    Status atual do chamado: ".$ticket->getStatus()."
                    Criador da resposta: ".$ticket->getLastRespondent()->getName()->name."
                    Data da postagem: ".$ticket->getLastResponseDate()."
                    Anexo(s):\n".self::getAttachmentsData($id)."
                    Resposta:\n".self::parseMessageForChat($extra);
                $bot = self::getDataTableBots(False);
                break;
            default:
                $msg = "Uma nova nota interna foi criada!\n
                    Proprietario: ".$ticket->getOwner()->getName()->name."
                    E-mail: ".$ticket->getOwner()->getEmail()."
                    Data de criação: ".$ticket->getCreateDate()."
                    Número: ".$ticket->getNumber()."
                    Título do chamado: ".$ticket->getSubject()."
                    Status atual do chamado: ".$ticket->getStatus()."
                    Criador da nota: ".$extra['posterName']."
                    Data da postagem: ".$extra['postDate']."
                    Anexo(s):\n".self::getAttachmentsData($id)."
                    Nota:\n".self::parseMessageForChat($extra['note']);
                $roomId = self::getDataTableStaffGroup()['group_id'];
                break;
        }
        if(!isset($bot)){
            $bot = self::getDataTableBots(True);
        }
        $params = array('header' => array('Content-Type:application/json', 
            'X-Auth-Token:'.$bot['bot_auth_token'], 
            'X-User-Id:'.$bot['bot_id']));
        if(!isset($roomId)){
            $roomId = array();
	    $params['url'] = 'im.create';
            if(($owner = $ticket->getOwner()->getUserNameForChat()) != $poster){
                $params['data'] = array('username' => $owner);
                array_push($roomId, self::makeRequestChat($params)->room->_id);
            }
            foreach($ticket->getCollaborators() as $collaborator){
                if(($username = $collaborator->getUserNameForChat()) != $poster){
                    $params['data']['username'] = $username;
                    array_push($roomId, self::makeRequestChat($params)->room->_id);
                }
            }
        }
        $params['url'] = 'chat.postMessage';
        self::sendMessageForChat($params, $roomId, $msg);
    }

    function sendMessageForChat($params, $roomId, $msg){
        if(is_array($roomId)){
            foreach($roomId as $userRoom){
                $params['data'] = array('roomId' => $userRoom, 'text' => $msg);
                self::makeRequestChat($params);
            }
        }
        else{
            $params['data'] = array('roomId' => $roomId, 'text' => $msg);
            self::makeRequestChat($params);
        }
    }
}

?>
