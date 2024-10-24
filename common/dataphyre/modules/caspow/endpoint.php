<?php
/*************************************************************************
*  2020-2024 Shopiro Ltd.
*  All Rights Reserved.
* 
* NOTICE:  All information contained herein is, and remains the 
* property of Shopiro Ltd. and its suppliers, if any. The 
* intellectual and technical concepts contained herein are 
* proprietary to Shopiro Ltd. and its suppliers and may be 
* covered by Canadian and Foreign Patents, patents in process, and 
* are protected by trade secret or copyright law. Dissemination of 
* this information or reproduction of this material is strictly 
* forbidden unless prior written permission is obtained from Shopiro Ltd..
*/

require_once($rootpath['common_dataphyre']."core.php");

$method=$_SERVER['REQUEST_METHOD'];

switch($_PARAM['action']){
    case'create':
        if($method==='GET'){
            create_challenge();
        }
		else
		{
            http_response_code(405);
        }
        break;
    case'verify':
        if($method==='POST'){
            verify_payload();
        }
		else
		{
            http_response_code(405);
        }
        break;
    default:
        http_response_code(404);
        echo json_encode(['error'=>'Endpoint not found']);
}

function create_challenge(){
    $response=dataphyre\caspow::create_challenge();
    echo json_encode($response);
}

function verify_payload(){
    $payload=file_get_contents('php://input');
    $isValid=dataphyre\caspow::verify_payload($payload);
    echo json_encode(['valid'=>$isValid]);
}

header('Content-Type: application/json');
