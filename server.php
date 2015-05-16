<?php
session_start();
class Json{
	private $json;
	private $warnings;
	private $errors;
	public function clear(){
		$this->warnings = Array();
		$this->errors = Array();
		$this->json = Array();
	}
	public function __construct(){
		$this->clear();
	}
	public function addWarning($s){
		$this->warnings[] = $s;
	}
	public function addError($s){
		$this->errors[] = $s;
	}
	public function add($key,$value){
		$this->json[$key] = $value;
	}
	public function get(){
		$this->json['warnings'] = $this->warnings;
		$this->json['errors'] = $this->errors;
		return json_encode($this->json);
	}
	public function hasErrors(){
		return sizeof($this->errors) > 0;
	}
	public function hasWarnings(){
		return sizeof($this->warnings) > 0;
	}
	public function getIndex($key){
		if(isset($this->json[$key])){
			return $this->json[$key];
		}
		return '';
	}
	public function deleteIndex($key){
		unset($this->json[$key]);
	}
}
$json = new Json();

function errorHandler($errno,$errstr,$errfile,$errline){
	global $json;
	switch($errno){
		case E_USER_WARNING:
		case E_USER_NOTICE:
			$json->addWarning(Array('type' => 'php','number' => $errno,'message'=>$errstr,'file' => $errfile,'line' => $errline));
			break;
		//case E_USER_ERROR: // no need, already caught by default.
		default:
			$json->addError(Array('type' => 'php','number' => $errno,'message'=>$errstr,'file' => $errfile,'line' => $errline));
	}
}
ini_set('display_errors',1);
error_reporting(E_ALL);
set_error_handler('errorHandler',E_ALL);
header('Content-Type: text/json');
include_once(realpath(dirname(__FILE__)).'/sql.php');
include_once(realpath(dirname(__FILE__)).'/vars.php');
date_default_timezone_set($vars->get('timezone'));
function can_int($v){
	return preg_match('/^[0-9]+$/',$v);
}
class Account{
	private $loggedIn;
	private $admin;
	function __construct(){
		global $sql,$json;
		$this->loggedIn = false;
		$this->admin = false;
		if(isset($_COOKIE['PHPSESSID']) && isset($_SESSION['id'])){
			$res = $sql->query("SELECT `admin` FROM `users` WHERE `id`=%d",array($_SESSION['id']),0);
			if($res['admin'] !== NULL){
				$this->loggedIn = true;
				if($res['admin'] == 1){
					$this->admin = true;
				}
			}
		}
		$json->add('isLoggedIn',$this->loggedIn);
		$json->add('isAdmin',$this->admin);
	}
	public function isAdmin(){
		return $this->admin;
	}
	public function logout(){
		unset($_SESSION['id']);
	}
	public function login($username,$pwd){
		global $sql,$json;
		$this->logout();
		$res = $sql->query("SELECT `hash`,`salt`,`id`,`username` FROM `users` WHERE LOWER(`username`) = LOWER('%s')",array($username),0);
		if($res['id'] != NULL && hash_hmac('sha512',$pwd,$res['salt']) == $res['hash']){
			$_SESSION['id'] = (int)$res['id'];
			$json->add('success',true);
			$json->add('username',$res['username']);
		}else{
			$json->add('success',false);
		}
	}
	private function generateRandomString($length = 10) {
		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$randomString = '';
		for($i = 0;$i < $length;$i++) {
			$randomString .= $characters[rand(0, strlen($characters) - 1)];
		}
		return $randomString;
	}
	public function newUser($username,$pwd){
		global $sql,$json;
		if(!$this->admin){
			$json->add('success',false);
			return;
		}
		$user = $sql->query("SELECT `id` FROM `users` WHERE LOWER(`username`)=LOWER('%s')",array($username),0);
		if($user['id'] === NULL && strlen($pwd) >= 1){
			$salt = $this->generateRandomString(50);
			$hash = hash_hmac('sha512',$pwd,$salt);
			$sql->query("INSERT INTO `users` (`username`,`hash`,`salt`) VALUES ('%s','%s','%s')",array($username,$hash,$salt));
			$json->add('success',true);
		}else{
			$json->add('success',false);
		}
	}
	public function editPwd($id,$pwd){
		global $sql,$json;
		if(!$this->admin || $id != (int)$id){
			$json->add('success',false);
			return;
		}
		$user = $sql->query("SELECT `id` FROM `users` WHERE `id`=%d",array((int)$id),0);
		if($user['id'] !== NULL){
			$salt = $this->generateRandomString(50);
			$hash = hash_hmac('sha512',$pwd,$salt);
			$sql->query("UPDATE `users` SET `hash`='%s',`salt`='%s' WHERE `id`=%d",array($hash,$salt,(int)$id));
			$json->add('success',true);
		}else{
			$json->add('success',false);
		}
	}
	public function getUserInfo(){
		global $sql,$json;
		if(!$this->admin){
			return;
		}
		$res = $sql->query("SELECT `id`,`username`,`admin` FROM `users`");
		$u = array();
		foreach($res as $r){
			if($r['id'] !== NULL){
				$u[] = array(
					'id' => (int)$r['id'],
					'username' => $r['username'],
					'admin' => ($r['admin']==1?true:false)
				);
			}
		}
		$json->add('info',$u);
	}
	public function remAdmin($id){
		global $sql;
		if(!$this->admin){
			return;
		}
		$sql->query("UPDATE `users` SET `admin`=0 WHERE `id`=%d",array($id));
	}
	public function addAdmin($id){
		global $sql;
		if(!$this->admin){
			return;
		}
		$sql->query("UPDATE `users` SET `admin`=1 WHERE `id`=%d",array($id));
	}
}
$you = new Account();

class Images{
	private $uploadDir = 'uploads/';
	private $maxfilesize = 20971520;
	private function error($message){
		global $json;
		$json->addError(
			Array(
				'type' => 'image handler',
				'message' => $message
			)
		);
		$json->add('upload-error',$message);
	}
	private function warning($message){
		global $json;
		$json->addWarning(
			Array(
				'type' => 'image handler',
				'message' => $message
			)
		);
	}
	private function realUpload($tmpName,$fileName){
		if(preg_match('#([ !\#$%\'()+-.\d;=@-\[\]-{}~]+)\.(\w+)$#',$fileName,$name)){
			$extension = strtolower($name[2]);
			if(in_array($extension,array('png','gif','jpg','jpeg','bmp','wbmp','pdf'))){
				for($i = 0;file_exists($this->uploadDir.$name[0]);$name[0] = $name[1].'-'.(++$i).'.'.$name[2]); // fix names
				$fName = $name[0];
				$name = $this->uploadDir.$name[0];
				if(move_uploaded_file($tmpName,$name)){
					if(filesize($name) < $this->maxfilesize){
						$valid = false;
						switch($extension){
							case 'pdf':
								$fp = fopen($name,'r');
								if(fread($fp,5)=='%PDF-'){
									$valid = true;
								}
								fclose($fp);
								break;
							default:
								if($j = @imagecreatefromstring($h = file_get_contents($name)) or substr($h,0,2) == 'BM'){
									imagedestroy($j);
									$valid = true;
								}
						}
						if($valid){
							
							return $fName;
						}else{
							unlink($name);
							$this->warning('File format unrecognized!');
						}
					}else{
						unlink($name);
						$this->warning('File too large!');
					}
				}else{
					$this->warning('Error uploading file!');
				}
			}else{
				$this->warning('Invalid Filetype');
			}
		}else{
			$this->warning('Invalid Filename');
		}
		return NULL;
	}
	public function upload(){
		global $json,$you;
		if($you->isAdmin()){
			if(sizeof($_FILES)>0 && isset($_FILES['file'])){
				$error = $_FILES['file']['error'];
				$ret = Array();
				if(!is_array($_FILES['file']['name'])){ // single file
					$fileName = $_FILES['file']['name'];
					$tmp = $this->realUpload($_FILES['file']['tmp_name'],$fileName);
					if($tmp!==NULL){
						$ret[] = $tmp;
					}
				}else{
					$fileCount = count($_FILES['file']['name']);
					for($i=0;$i < $fileCount;$i++){
						$fileName = $_FILES['file']['name'][$i];
						$tmp = $this->realUpload($_FILES['file']['tmp_name'][$i],$fileName);
						if($tmp!==NULL){
							$ret[] = $tmp;
						}
					}
				}
				$json->add('files',$ret);
				
			}else{
				$this->warning('No file to upload');
			}
		}else{
			$this->warning('permission denied');
		}
	}
	public function listFiles(){
		global $json,$you;
		if($you->isAdmin()){
			$a = scandir($this->uploadDir);
			$b = Array();
			foreach($a as $f){
				if($f!='.' && $f!='..'){
					if(preg_match('#([ !\#$%\'()+-.\d;=@-\[\]-{}~]+)\.(\w+)$#',$f,$name)){
						$extension = strtolower($name[2]);
						if(in_array($extension,array('png','gif','jpg','jpeg','bmp','wbmp','pdf'))){
							switch($extension){
								case 'pdf':
									$b[] = Array(
										'type' => 'pdf',
										'name' => $name[0]
									);
									break;
								default:
									$b[] = Array(
										'type' => 'img',
										'name' => $name[0]
									);
							}
						}
					}
				}
			}
			$json->add('files',$b);
		}else{
			$this->warning('permission denied');
		}
	}
}
$images = new Images();

class Variable{
	private function error($message){
		global $json;
		$json->addError(
			Array(
				'type' => 'variable parser',
				'message' => $message
			)
		);
	}
	private function warning($message){
		global $json;
		$json->addWarning(
			Array(
				'type' => 'variable parser',
				'message' => $message
			)
		);
	}
	private function validateVar($value,$varType){
		$varTypeConstruct = json_decode($varType['value'],true);
		switch((int)$varType['type']){
			case 0: //int
				if(!preg_match('@^[0-9]+([.,/][0-9]+|)$@',$value)){
					return false;
				}
				if($varTypeConstruct['max'] > $varTypeConstruct['min'] && (((float)$value<$varTypeConstruct['min']) || ((float)$value>$varTypeConstruct['max']))){
					return false;
				}
				return true;
			case 1: //text
				return true;
			case 2: //picklist
				if((int)$value==-1){
					return true;
				}else{
					if(!can_int($value)){
						return false;
					}
					foreach($varTypeConstruct as $pickListItem){
						if($pickListItem['id']==(int)$value){
							return true;
						}
					}
					return false;
				}
			case 3: //bild
				return true;
			case 4: //regex
				if(preg_match($varTypeConstruct['pattern'],$value)){
					return true;
				}
				return false;
			case 5: //time
				return true;
		}
	}
	public function getEditInfo($id){
		global $sql,$json,$you;
		if($you->isAdmin()){
			if(can_int($id)){
				$fetchVar = $sql->query("SELECT `refId`,`value` FROM `variables` WHERE `id`=%d",Array((int)$id),0);
				if($fetchVar['value']!==NULL){
					$varType = $sql->query("SELECT `value`,`type`,`id` FROM `variableTypes` WHERE `id`=%d",Array((int)$fetchVar['refId']),0);
					if($varType['type']!==NULL){
						$json->add('variableInfo',Array(
								'id' => $id,
								'type' => (int)$varType['type'],
								'value' => $fetchVar['value'],
								'construct' => json_decode($varType['value'],true),
								'varTypeId' => (int)$varType['id']
							));
					}else{
						$this->warning('Corrupt Variable');
					}
				}else{
					$this->warning('Variable not found');
				}
			}else{
				$this->warning('Invalid ID');
			}
		}else{
			$this->warning('permission denied');
		}
	}
	private function cacheVar($id){
		global $sql,$json;
		if(can_int($id)){
			$fetchVar = $sql->query("SELECT `refId`,`value` FROM `variables` WHERE `id`=%d",Array((int)$id),0);
			if($fetchVar['value']!==NULL){
				$varType = $sql->query("SELECT `value`,`type` FROM `variableTypes` WHERE `id`=%d",Array((int)$fetchVar['refId']),0);
				if($varType['type']!==NULL){
					if($fetchVar['value']==''){
						return '';
					}
					$varval = '';
					$varTypeConstruct = json_decode($varType['value'],true);
					switch((int)$varType['type']){
						case 0: //int
							if($this->validateVar($fetchVar['value'],$varType)){
								$varval = htmlspecialchars($varTypeConstruct['prefix'].(string)$fetchVar['value'].$varTypeConstruct['suffix']);
							}
							break;
						case 1: //text
							$varval = htmlspecialchars($fetchVar['value']);
							break;
						case 2: //picklist
							if((int)$fetchVar['value']!=-1 && $this->validateVar($fetchVar['value'],$varType)){
								$val = '';
								foreach($varTypeConstruct as $pickListItem){
									if($pickListItem['id']==(int)$fetchVar['value']){
										$val = $pickListItem['value'];
									}
								}
								$varval = htmlspecialchars($val);
							}
							break;
						case 3: //bild
							$type = 'img';
							if(preg_match('#([ !\#$%\'()+-.\d;=@-\[\]-{}~]+)\.(\w+)$#',$fetchVar['value'],$name)){
								$extension = strtolower($name[2]);
								if($extension=='pdf'){
									$type = 'pdf';
								}
							}
							switch($type){
								case 'img':
									$varval = '<a href="'.htmlspecialchars($fetchVar['value']).'" target="_blank"><img style="max-width:200px;max-height:200px;" src="'.htmlspecialchars($fetchVar['value']).'"></a>';
									break;
								case 'pdf':
									$varval = '<a href="'.htmlspecialchars($fetchVar['value']).'" target="_blank">PDF</a>';
									break;
							}
							break;
						case 4: //regex
							if($this->validateVar($fetchVar['value'],$varType)){
								$varval = htmlspecialchars(preg_replace($varTypeConstruct['pattern'],$varTypeConstruct['replace'],$fetchVar['value']));
							}
							break;
						case 5: //time
							$varval = htmlspecialchars($fetchVar['value']);
							break;
					}
					$sql->query("UPDATE `variables` SET `dispValue`='%s' WHERE `id`=%d",Array($varval,(int)$id));
				}else{
					$this->warning('Corrupt Variable');
				}
			}else{
				$this->warning('Variable not found');
			}
		}else{
			$this->warning('invalid id');
		}
	}
	public function get($id){
		global $sql,$json;
		if(can_int($id)){
			$fetchVar = $sql->query("SELECT `id`,`dispValue` FROM `variables` WHERE `id`=%d",Array((int)$id),0);
			if($fetchVar['id']!==NULL){
				if($fetchVar['dispValue']===NULL){
					$this->cacheVar($id);
					$fetchVar = $sql->query("SELECT `id`,`dispValue` FROM `variables` WHERE `id`=%d",Array((int)$id),0);
					if($fetchVar['dispValue']===NULL){
						$json->add('variable','');
					}else{
						$json->add('variable',$fetchVar['dispValue']);
					}
				}else{
					$json->add('variable',$fetchVar['dispValue']);
				}
			}else{
				$this->warning('Variable not found');
			}
		}else{
			$this->warning('invalid id');
		}
	}
	public function clearCache($id){
		global $sql,$vars;
		if(can_int($id)){
			$sql->query("UPDATE `variables` SET `dispValue` = NULL WHERE `id`=%d",Array((int)$id));
			$objs = $sql->query("SELECT `id`,`categories` FROM `objects` WHERE `value` LIKE '%s'",Array('%"varId":'.(int)$id.'%'));
			foreach($objs as $obj){
				$vars->delete('cache_objects_'.(int)$obj['id']);
				foreach(explode('[',$obj['categories']) as $c){
					if($c!=']' && $c!='' && $c!='0]'){
						$vars->delete('cache_categories_'.(int)substr($c,0,-1));
					}
				}
			}
		}else{
			$this->warning('invalid id');
		}
	}
	public function save($id,$value){
		global $sql,$json,$vars,$you;
		if($you->isAdmin()){
			if(can_int($id)){
				$id = (int)$id;
				$fetchVar = $sql->query("SELECT `refId`,`value` FROM `variables` WHERE `id`=%d",Array($id),0);
				if($fetchVar['value']!==NULL){
					$varType = $sql->query("SELECT `value`,`type` FROM `variableTypes` WHERE `id`=%d",Array((int)$fetchVar['refId']),0);
					if($varType['type']!==NULL){
						if($this->validateVar($value,$varType)){
							$sql->query("UPDATE `variables` SET `value`='%s' WHERE `id`=%d",Array($value,$id));
							
							$this->clearCache($id);
							$this->cacheVar($id);
							
							$json->add('success',true);
						}else{
							$json->add('success',false);
						}
					}else{
						$this->warning('Corrupt Variable');
					}
				}else{
					$this->warning('Variable not found');
				}
			}else{
				$this->warning('invalid id');
			}
		}else{
			$this->warning('permission denied');
		}
	}
	public function newVar($id){
		global $sql,$json,$you;
		if($you->isAdmin()){
			if(can_int($id)){
				$varType = $sql->query("SELECT `name` FROM `variableTypes` WHERE `id`=%d",Array((int)$id),0);
				if($varType['name']!==NULL){
					$sql->query("INSERT INTO `variables` (`refId`,`value`) VALUES (%d,'')",Array((int)$id));
					$json->add('varId',$sql->insertId());
				}else{
					$this->warning('invalid variable type');
				}
			}else{
				$this->warning('invalid id');
			}
		}else{
			$this->warning('permission denied');
		}
	}
	public function addPicklistItem($id,$value){
		global $sql,$json,$you;
		if($you->isAdmin()){
			if(can_int($id)){
				$varType = $sql->query("SELECT `value`,`type` FROM `variableTypes` WHERE `id`=%d",Array((int)$id),0);
				if((int)$varType['type'] == 2){
					$varTypeConstruct = json_decode($varType['value'],true);
					$maxId = 0;
					foreach($varTypeConstruct as $varTypeC){
						if($varTypeC['id'] > $maxId){
							$maxId = $varTypeC['id'];
						}
					}
					$varTypeConstruct[] = Array(
							'id' => $maxId+1,
							'value' => $value
						);
					$sql->query("UPDATE `variableTypes` SET `value`='%s' WHERE `id`=%d",Array(json_encode($varTypeConstruct),(int)$id));
					$json->add('id',$maxId+1);
					$json->add('name',$value);
				}else{
					$this->warning('Not a picklist');
				}
			}else{
				$this->warning('Invalid ID');
			}
		}else{
			$this->warning('permission denied');
		}
	}
	public function delete($id){
		global $sql,$you;
		if($you->isAdmin()){
			if(can_int($id)){
				$sql->query("DELETE FROM `variables` WHERE `id`=%d",Array($id));
			}else{
				$this->warning('Invalid ID');
			}
		}else{
			$this->warning('permission denied');
		}
	}
	public function listTypes(){
		global $sql,$json,$you;
		if($you->isAdmin()){
			$types = $sql->query("SELECT `id`,`name`,`type`,`tooltip` FROM `variableTypes`");
			$jsonTypes = Array();
			foreach($types as $t){
				$jsonTypes[] = Array(
					'id' => (int)$t['id'],
					'name' => $t['name'],
					'type' => (int)$t['type'],
					'tooltip' => $t['tooltip']
				);
			}
			$json->add('variableTypes',$jsonTypes);
		}else{
			$this->warning('permission denied');
		}
	}
	public function getEditVarTypeInfo($id){
		global $sql,$json,$you;
		if($you->isAdmin()){
			if(can_int($id)){
				$id = (int)$id;
				$varType = $sql->query("SELECT `id`,`name`,`value`,`type`,`tooltip` FROM `variableTypes` WHERE `id`=%d",Array($id),0);
				if($varType['id'] !== NULL){
					$json->add('variableType',Array(
						'id' => (int)$varType['id'],
						'name' => $varType['name'],
						'value' => json_decode($varType['value'],true),
						'type' => (int)$varType['type'],
						'tooltip' => $varType['tooltip']
					));
				}else{
					$this->warning('Variable Type not found');
				}
			}else{
				$this->warning('Invalid ID');
			}
		}else{
			$this->warning('permission denied');
		}
	}
	public function saveVarType($id,$name,$tooltip,$value){
		global $sql,$json,$you;
		if($you->isAdmin()){
			if(can_int($id)){
				$id = (int)$id;
				$varType = $sql->query("SELECT `id` FROM `variableTypes` WHERE `id`=%d",Array($id),0);
				if($varType['id'] !== NULL){
					$sql->query("UPDATE `variableTypes` SET `name`='%s',`tooltip`='%s',`value`='%s' WHERE `id`=%d",Array($name,$tooltip,$value,$id));
					$res = $sql->query("SELECT `id` FROM `variables` WHERE `refId`=%d",Array($id));
					foreach($res as $r){
						if($r['id'] !== NULL){
							$this->clearCache((int)$r['id']);
						}
					}
					$json->add('success',true);
				}else{
					$this->warning('Variable Type not found');
				}
			}else{
				$this->warning('Invalid ID');
			}
		}else{
			$this->warning('permission denied');
		}
	}
	public function newVarType($type){
		global $json,$sql,$you;
		if($you->isAdmin()){
			$construct = false;
			switch($type){
				case 0:
					$construct = Array(
						'min' => 0,
						'max' => -1,
						'prefix' => '',
						'suffix' => ''
					);
					break;
				case 1:
					$construct = Array();
					break;
				case 2:
					$construct = Array();
					break;
				case 3:
					$constuct = Array();
					break;
				case 4:
					$construct = Array(
						'pattern' => '',
						'replace' => ''
					);
					break;
			}
			if($construct!==false){
				$sql->query("INSERT INTO `variableTypes` (`value`,`type`) VALUES ('%s',%d)",Array(json_encode($construct),(int)$type));
				$json->add('id',$sql->insertId());
			}else{
				$this->warning('Unknown variable type id');
			}
		}else{
			$this->warning('permission denied');
		}
	}
}
$variable = new Variable();

class Object{
	private function error($message){
		global $json;
		$json->addError(
			Array(
				'type' => 'object parser',
				'message' => $message
			)
		);
	}
	private function warning($message){
		global $json;
		$json->addWarning(
			Array(
				'type' => 'object parser',
				'message' => $message
			)
		);
	}
	public function getEditInfo($id){
		global $json,$sql,$variable,$category,$vars,$you;
		if($you->isAdmin()){
			if(can_int($id)){
				$fetchObj = $sql->query("SELECT `value`,`refId`,`categories` FROM `objects` WHERE `id`=%d",Array((int)$id),0);
				if($fetchObj['value']!==NULL){
					$objType = $sql->query("SELECT `value` FROM `objectTypes` WHERE `id`=%d",Array((int)$fetchObj['refId']),0);
					if($objType['value']!==NULL){
						$obj = Array();
						
						$objConstruct = json_decode($fetchObj['value'],true);
						$objTypeConstruct = json_decode($objType['value'],true);
						
						$newVar = false;
						foreach($objTypeConstruct as $objT){
							$found = false;
							for($i=0;$i<sizeof($objConstruct);$i++){
								if($objT['id']==$objConstruct[$i]['objId']){
									$variable->getEditInfo($objConstruct[$i]['varId']);
									$varJSON = $json->getIndex('variableInfo');
									$varJSON['name'] = $objT['name'];
									$obj[] = $varJSON;
									$json->deleteIndex('variableInfo');
									$found = true;
								}
							}
							if(!$found){
								$variable->newVar($objT['varType']);
								$newVarId = $json->getIndex('varId');
								$variable->getEditInfo($newVarId);
								
								$json->deleteIndex('varId');
								
								$varJSON = $json->getIndex('variableInfo');
								$varJSON['name'] = $objT['name'];
								$obj[] = $varJSON;
								$objConstruct[] = Array(
									'objId' => $objT['id'],
									'varId' => $newVarId
								);
								$newVar = true;
							}
						}
						
						if($newVar){
							$sql->query("UPDATE `objects` SET `value`='%s' WHERE `id`=%d",Array(json_encode($objConstruct),(int)$id));
						}
						
						$json->add('objectInfo',$obj);
					}else{
						$this->warning('Corrupt object');
					}
				}else{
					$this->warning('Object not found');
				}
			}else{
				$this->warning('invalid id');
			}
		}else{
			$this->warning('permission denied');
		}
	}
	public function get($id){
		global $json,$sql,$variable,$category,$vars;
		if(can_int($id)){
			$fetchObj = $sql->query("SELECT `value`,`refId`,`categories`,`primcat` FROM `objects` WHERE `id`=%d",Array((int)$id),0);
			if($fetchObj['value']!==NULL){
				$objType = $sql->query("SELECT `value` FROM `objectTypes` WHERE `id`=%d",Array((int)$fetchObj['refId']),0);
				if($objType['value']!==NULL){
					$cache = $vars->get('cache_objects_'.(int)$id);
					if(!$cache){
						$cache = Array();
						$obj = Array();
						
						$objConstruct = json_decode($fetchObj['value'],true);
						$objTypeConstruct = json_decode($objType['value'],true);
						foreach($objTypeConstruct as $objT){
							$found = false;
							for($i=0;$i<sizeof($objConstruct);$i++){
								if($objT['id']==$objConstruct[$i]['objId']){
									$variable->get($objConstruct[$i]['varId']);
									$obj[$objT['name']] = Array(
										'value' => $json->getIndex('variable'),
										'quick' => $objT['quick']
									);
									$json->deleteIndex('variable');
									$found = true;
								}
							}
							if(!$found){
								$obj[$objT['name']] = Array(
									'value' => '',
									'quick' => $objT['quick']
								);
							}
						}
						$obj['id'] = (int)$id;
						$cache['object'] = $obj;
						$json->add('object',$obj);
						
						$category->parseCats($fetchObj['categories']);
						$cache['categories'] = $json->getIndex('categories');
						$vars->set('cache_objects_'.(int)$id,$cache);
					}else{
						$json->add('object',$cache['object']);
						$json->add('categories',$cache['categories']);
					}
					$json->add('cattree',$category->getCatTree($fetchObj['primcat']));
					$json->add('primcat',(int)$fetchObj['primcat']);
				}else{
					$this->warning('Corrupt object');
				}
			}else{
				$this->warning('Object not found');
			}
		}else{
			$this->warning('invalid id');
		}
	}
	public function addCSVStream($id,&$output,$header = false,$categories = false){
		global $json;
		if(is_array($id)){
			$json->add('object',$id['object']);
			$json->add('categories',$id['categories']);
		}else{
			$this->get($id);
		}
		if($json->hasWarnings){
			fputcsv($output,Array('Something went wrong!'));
		}else{
			$obj = $json->getIndex('object');
			$json->deleteIndex('object');
			$h = Array();
			$v = Array();
			foreach($obj as $key => $val){
				if($key!='id'){
					$h[] = $key;
					$v[] = $val['value'];
				}
			}
			if($header){
				fputcsv($output,$h);
			}
			fputcsv($output,$v);
			if($categories){
				$cats = $json->getIndex('categories');
				$json->deleteIndex('categories');
				fputcsv($output,Array());
				fputcsv($output,Array());
				fputcsv($output,Array('Categories:'));
				$c = Array();
				foreach($cats as $v){
					$c[] = $v['name'];
				}
				fputcsv($output,$c);
			}
		}
	}
	public function getCSV($id){
		header('Content-Type:application/csv');
		header('Content-Disposition:attachment;filename=object.csv');
		$output = fopen('php://output','w') or die("Can't open php://output");
		
		$this->addCSVStream($id,$output,true,true);
		
		fclose($output) or die("Can't close php://output");
		exit;
	}
	public function newObj($id,$cats){
		global $sql,$variable,$json,$variable,$you;
		if($you->isAdmin()){
			if(can_int($id)){
				$objType = $sql->query("SELECT `value` FROM `objectTypes` WHERE `id`=%d",Array((int)$id),0);
				if($objType['value']!==NULL){
					$objTypeConstruct = json_decode($objType['value'],true);
					$newConstruct = Array();
					foreach($objTypeConstruct as $otc){
						$variable->newVar($otc['varType']);
						$newConstruct[] = Array(
								'objId' => (int)$otc['id'],
								'varId' => (int)$json->getIndex('varId')
							);
						$json->deleteIndex('varId');
					}
					$variable->clearCache($newConstruct[0]['varId']);
					$newCats = '';
					foreach($cats as $c){
						$newCats .= '['.(int)$c.']';
					}
					$sql->query("INSERT INTO `objects` (`refId`,`value`,`categories`) VALUES (%d,'%s','%s')",Array((int)$id,json_encode($newConstruct),$newCats));
					$json->add('objId',$sql->insertId());
				}else{
					$this->warning('invalid object type');
				}
			}else{
				$this->warning('invalid id');
			}
		}else{
			$this->warning('permission denied');
		}
	}
	public function delete($id){
		global $sql,$json,$vars,$variable,$category,$you;
		if($you->isAdmin()){
			if(can_int($id)){
				$obj = $sql->query("SELECT `id`,`value`,`categories` FROM `objects` WHERE `id`=%d",Array($id),0);
				if($obj['id']!==NULL){
					$objConstruct = json_decode($obj['value'],true);
					foreach($objConstruct as $objC){
						$variable->delete($objC['varId']);
					}
					$firstCat = 1;
					$category->parseCats($obj['categories']);
					$cats = $json->getIndex('categories');
					$json->deleteIndex('categories');
					if(isset($cats[0])){
						$firstCat = $cats[0]['id'];
					}
					
					$sql->query("DELETE FROM `objects` WHERE `id`=%d",Array($id));
					
					foreach($cats as $cat){
						$vars->delete('cache_categories_'.$cat['id']);
					}
					$vars->delete('cache_objects_'.$id);
					
					$json->add('goto',(int)$firstCat);
				}else{
					$this->warning('Object not found');
				}
			}else{
				$this->warning('Invalid ID');
			}
		}else{
			$this->warning('permission denied');
		}
	}
	public function listObjTypes(){
		global $json,$sql,$you;
		if($you->isAdmin()){
			$res = $sql->query("SELECT `id`,`name` FROM `objectTypes`");
			$jsonRes = Array();
			foreach($res as $o){
				if($o['id'] !== NULL){
					$jsonRes[] = Array(
						'id' => (int)$o['id'],
						'name' => $o['name']
					);
				}
			}
			$json->add('objectTypes',$jsonRes);
		}else{
			$this->warning('permission denied');
		}
	}
	public function getEditObjTypeInfo($id){
		global $sql,$json,$you;
		if($you->isAdmin()){
			if(can_int($id)){
				$id = (int)$id;
				$objType = $sql->query("SELECT `id`,`name`,`value` FROM `objectTypes` WHERE `id`=%d",Array($id),0);
				if($objType['id'] !== NULL){
					$json->add('objectType',Array(
						'id' => (int)$objType['id'],
						'name' => $objType['name'],
						'value' => json_decode($objType['value'],true)
					));
					
					$res = $sql->query("SELECT `id`,`name` FROM `variableTypes`");
					$jsonRet = Array();
					foreach($res as $r){
						if($r['id'] !== NULL){
							$jsonRet[(int)$r['id']] = $r['name'];
						}
					}
					$json->add('variableTypes',$jsonRet);
				}else{
					$this->warning('Object type not found');
				}
			}else{
				$this->warning('invalid id');
			}
		}else{
			$this->warning('permission denied');
		}
	}
	public function saveObjType($id,$name,$value){
		global $sql,$json,$vars,$you;
		if($you->isAdmin()){
			if(can_int($id)){
				$id = (int)$id;
				$objType = $sql->query("SELECT `id` FROM `objectTypes` WHERE `id`=%d",Array($id),0);
				if($objType['id'] !== NULL){
					$sql->query("UPDATE `objectTypes` SET `name`='%s',`value`='%s' WHERE `id`=%d",Array($name,$value,$id));
					
					$objs = $sql->query("SELECT `id`,`categories` FROM `objects` WHERE `refId`=%d",Array($id));
					foreach($objs as $obj){
						$vars->delete('cache_objects_'.(int)$obj['id']);
						foreach(explode('[',$obj['categories']) as $c){
							if($c!=']' && $c!='' && $c!='0]'){
								$vars->delete('cache_categories_'.(int)substr($c,0,-1));
							}
						}
					}
					
					$json->add('success',true);
				}else{
					$this->warning('Object type not found');
				}
			}else{
				$this->warning('invalid id');
			}
		}else{
			$this->warning('permission denied');
		}
	}
	public function newObjType(){
		global $json,$sql,$you;
		if($you->isAdmin()){
			$sql->query("INSERT INTO `objectTypes` (`value`) VALUES ('[]')");
			$json->add('id',$sql->insertId());
		}else{
			$this->warning('permission denied');
		}
	}
	public function getUncategorized(){
		global $sql,$json;
		$retObjs = Array();
		$fetchObjs = $sql->query("SELECT `id` FROM `objects` WHERE NOT `categories` LIKE '%s'",Array('%[%'));
		foreach($fetchObjs as $o){
			if($o['id']!==NULL){
				$this->get((int)$o['id']);
				$retObjs[] = $json->getIndex('object');
				$json->deleteIndex('object');
				$json->deleteIndex('categories');
			}
		}
		$json->add('uncatObjs',$retObjs);
	}
}
$object = new Object();

class Category{
	private function error($message){
		global $json;
		$json->addError(
			Array(
				'type' => 'category parser',
				'message' => $message
			)
		);
	}
	private function warning($message){
		global $json;
		$json->addWarning(
			Array(
				'type' => 'category parser',
				'message' => $message
			)
		);
	}
	public function parseCats($s){
		global $sql,$json;
		$cat = Array();
		$s = substr($s,1,strlen($s)-2);
		foreach(explode('][',$s) as $c){
			if($c!='' && $c!='0' && can_int($c)){
				$c = (int)$c;
				$res = $sql->query("SELECT `name` FROM `categories` WHERE `id`=%d",Array($c),0);
				$cat[] = Array(
					'name' => $res['name'],
					'id' => $c
				);
			}
		}
		
		$json->add('categories',$cat);
	}
	public function getCatTree($id){
		global $sql;
		$res = $sql->query("SELECT `primcat`,`name`,`id` FROM `categories` WHERE `id`=%d",Array((int)$id),0);
		if($res['primcat'] == 0 || $res['primcat']===NULL){
			$a = Array();
		}else{
			$a = $this->getCatTree($res['primcat']);
		}
		return array_merge($a,Array(Array(
				'name' => $res['name'],
				'id' => $res['id']
			)));
	}
	public function get($id){
		global $sql,$json,$object,$vars;
		if(can_int($id)){
			$fetchCat = $sql->query("SELECT `name`,`categories`,`primcat` FROM `categories` WHERE `id`=%d",Array((int)$id),0);
			if($fetchCat['name'] !== NULL){
				$cache = $vars->get('cache_categories_'.(int)$id);
				if(!$cache){
					$cache = Array();
					$refIds = Array();
					$objsToGiveOut = Array();
					$objs = $sql->query("SELECT `id`,`refId` FROM `objects` WHERE `categories` LIKE '%s'",Array('%['.(int)$id.']%'));
					foreach($objs as $obj){
						if($obj['id']!==NULL){
							if(!isset($refIds[$obj['refId']])){
								$res = $sql->query("SELECT `name` FROM `objectTypes` WHERE `id`=%d",Array((int)$obj['refId']),0);
								$refIds[$obj['refId']] = $res['name'];
								$objsToGiveOut[$res['name']] = Array(
										'id' => (int)$obj['refId'],
										'objects' => Array()
									);
							}
							$object->get((int)$obj['id']);
							
							$objsToGiveOut[$refIds[$obj['refId']]]['objects'][] = $json->getIndex('object');
							$json->deleteIndex('object');
							$json->deleteIndex('categories');
						}
					}
					$cache['objects'] = $objsToGiveOut;
					$json->add('objects',$objsToGiveOut);
					
					$catsToGiveOut = Array();
					$cats = $sql->query("SELECT `id`,`name` FROM `categories` WHERE `categories` LIKE '%s'",Array('%['.(int)$id.']%'));
					foreach($cats as $cat){
						if($cat['id']!==NULL){
							$catsToGiveOut[] = Array(
									'name' => $cat['name'],
									'id' => $cat['id']
								);
						}
					}
					$cache['subcategories'] = $catsToGiveOut;
					$json->add('subcategories',$catsToGiveOut);
					
					$cache['name'] = $fetchCat['name'];
					$json->add('name',$fetchCat['name']);
					
					$this->parseCats($fetchCat['categories']);
					$cache['categories'] = $json->getIndex('categories');
					
					
					$vars->set('cache_categories_'.(int)$id,$cache);
				}else{
					$json->add('objects',$cache['objects']);
					$json->add('subcategories',$cache['subcategories']);
					$json->add('categories',$cache['categories']);
					$json->add('name',$cache['name']);
				}
				$json->add('cattree',$this->getCatTree($fetchCat['primcat']));
				$json->add('primcat',(int)$fetchCat['primcat']);
			}else{
				$this->warning('Category not found');
			}
		}else{
			$this->warning('invalid id');
		}
	}
	public function addCSVStream($id,&$output){
		global $json,$object;
		$this->get($id);
		if($json->hasWarnings){
			fputcsv($output,Array('Something went wrong!'));
		}else{
			fputcsv($output,Array($json->getIndex('name')));
			$json->deleteIndex('name');
			$subcats = $json->getIndex('subcategories');
			$json->deleteIndex('subcategories');
			$cats = $json->getIndex('categories');
			$json->deleteIndex('categories');
			fputcsv($output,Array());
			fputcsv($output,Array());
			fputcsv($output,Array('Objects:'));
			fputcsv($output,Array());
			$objs = $json->getIndex('objects');
			foreach($objs as $key => $val){
				fputcsv($output,Array($key.':'));
				$first = true;
				foreach($val['objects'] as $o){
					$object->addCSVStream(Array('object' => $o,'categories' => Array()),$output,$first);
					$first = false;
				}
			}
			fputcsv($output,Array());
			fputcsv($output,Array('Subcategories:'));
			$c = Array();
			foreach($subcats as $v){
				$c[] = $v['name'];
			}
			fputcsv($output,$c);
			
			fputcsv($output,Array());
			fputcsv($output,Array('Categories:'));
			$c = Array();
			foreach($cats as $v){
				$c[] = $v['name'];
			}
			fputcsv($output,$c);
		}
	}
	public function getCSV($id){
		global $sql;
		header('Content-Type:application/csv');
		if($id == 1){
			header('Content-Disposition:attachment;filename=database.csv');
			$output = fopen('php://output','w') or die("Can't open php://output");
			$cats = $sql->query("SELECT `name`,`id` FROM `categories`");
			foreach($cats as $c){
				$this->addCSVStream($c['id'],$output);
				
				fputcsv($output,Array("=================="));
				fputcsv($output,Array());
			}
		}else{
			header('Content-Disposition:attachment;filename=category.csv');
			$output = fopen('php://output','w') or die("Can't open php://output");
			
			$this->addCSVStream($id,$output);
		}
		fclose($output) or die("Can't close php://output");
		exit;
	}
	public function getHint($s){
		global $sql,$json;
		$cats = $sql->query("SELECT `name` FROM `categories` WHERE LOWER(`name`) LIKE '%s'",Array(strtolower($s).'%'));
		$resCats = Array();
		foreach($cats as $cat){
			if($cat['name']!==NULL){
				$resCats[] = $cat['name'];
			}
		}
		$json->add('hintCats',$resCats);
	}
	public function newCat($s){
		global $json,$sql,$sql,$you;
		if($you->isAdmin()){
			$cat = $sql->query("SELECT `id` FROM `categories` WHERE LOWER(`name`)=LOWER('%s')",Array($s),0);
			if($cat['id']===NULL){
				$sql->query("INSERT INTO `categories` (`name`) VALUES ('%s')",Array($s));
				$json->add('catId',$sql->insertId());
				$json->add('duplicate',false);
			}else{
				$json->add('duplicate',true);
			}
		}else{
			$this->warning('permission denied');
		}
	}
	public function editName($id,$s){
		global $sql,$json,$vars,$you;
		if($you->isAdmin()){
			if(can_int($id)){
				$fetchCat = $sql->query("SELECT `id` FROM `categories` WHERE `id`=%d",Array((int)$id),0);
				if($fetchCat['id'] !== NULL){
					$cat = $sql->query("SELECT `id` FROM `categories` WHERE LOWER(`name`)=LOWER('%s')",Array($s),0);
					if($cat['id']===NULL){
						$json->add('duplicate',false);
						$sql->query("UPDATE `categories` SET `name`='%s' WHERE `id`=%d",Array($s,(int)$id));
						
						$this->get($id);
						foreach($json->getIndex('categories') as $c){
							$vars->delete('cache_categories_'.$c['id']);
						}
						foreach($json->getIndex('objects') as $objs){
							foreach($objs['objects'] as $o){
								$vars->delete('cache_objects_'.$o['id']);
							}
						}
						foreach($json->getIndex('subcategories') as $c){
							$vars->delete('cache_categories_'.$c['id']);
						}
						
						$vars->delete('cache_categories_'.$id);
						$json->deleteIndex('objects');
						$json->deleteIndex('subcategories');
						$json->deleteIndex('categories');
						$json->deleteIndex('name');
						
					}else{
						$json->add('duplicate',true);
					}
				}else{
					$this->warning('Category not found');
				}
			}else{
				$this->warning('invalid id');
			}
		}else{
			$this->warning('permission denied');
		}
	}
	public function addTo($id,$s){
		global $sql,$json,$vars,$you;
		if($you->isAdmin()){
			if(is_string($id) && strlen($id)>=2){
				$updateTable = NULL;
				switch($id[0]){
					case 'o':
						$updateTable = 'objects';
						break;
					case 'c':
						$updateTable = 'categories';
						break;
					default:
						$this->warning('invalid id');
				}
				if($updateTable!==NULL && $id != 'c1' /* don't allow category adding to root */){
					$id = substr($id,1);
					if(can_int($id)){
						$cat = $sql->query("SELECT `id`,`name` FROM `categories` WHERE LOWER(`name`)=LOWER('%s')",Array($s),0);
						if($cat['id']!==NULL){
							$res = $sql->query("SELECT `id`,`categories` FROM `%s` WHERE `id`=%d",Array($updateTable,$id),0);
							if($res['id']!==NULL){
								$this->parseCats($res['categories']);
								$oldCats = $json->getIndex('categories');
								$json->deleteIndex('categories');
								$exists = false;
								foreach($oldCats as $oldC){
									if($oldC['id'] == $cat['id']){
										$exists = true;
										break;
									}
								}
								if(!$exists){
									$oldCats[] = Array(
											'name' => $cat['name'],
											'id' => (int)$cat['id']
										);
									$catStr = '';
									foreach($oldCats as $c){
										$catStr .= '['.$c['id'].']';
									}
									$sql->query("UPDATE `%s` SET `categories`='%s' WHERE `id`=%d",Array($updateTable,$catStr,$id));
									$vars->delete('cache_categories_'.$cat['id']);
									$vars->delete('cache_'.$updateTable.'_'.$id);
									$json->add('success',true);
									$json->add('categories',$oldCats);
									$json->add('id',$cat['id']);
								}else{
									$json->add('success',false);
								}
							}else{
								$this->warning('not found');
							}
						}else{
							$this->warning('category not found');
						}
					}else{
						$this->warning('invalid id');
					}
				}else{
					$this->warning('Something went terribley wrong');
				}
			}else{
				$this->warning('invalid id');
			}
		}else{
			$this->warning('permission denied');
		}
	}
	public function removeFrom($id,$c){
		global $sql,$json,$vars,$you;
		if($you->isAdmin()){
			if(is_string($id) && strlen($id)>=2){
				$updateTable = NULL;
				switch($id[0]){
					case 'o':
						$updateTable = 'objects';
						break;
					case 'c':
						$updateTable = 'categories';
						break;
					default:
						$this->warning('invalid id');
				}
				if($updateTable!==NULL){
					$id = substr($id,1);
					if(can_int($id) && can_int($c)){
						$res = $sql->query("SELECT `id`,`categories`,`primcat` FROM `%s` WHERE `id`=%d",Array($updateTable,$id),0);
						if($res['id']!==NULL){
							$this->parseCats($res['categories']);
							$oldCats = $json->getIndex('categories');
							$json->deleteIndex('categories');
							$newCats = Array();
							foreach($oldCats as $oldC){
								if($oldC['id']!=$c){
									$newCats[] = $oldC;
								}
							}
							$catStr = '';
							foreach($newCats as $nc){
								$catStr .= '['.$nc['id'].']';
							}
							$newPrimCat = (int)$res['primcat'];
							if($c == $res['primcat']){
								if(sizeof($newCats) > 0){
									$newPrimCat = $newCats[0]['id'];
								}
								$sql->query("UPDATE `%s` SET `primcat`=%d WHERE `id`=%d",Array($updateTable,(int)$newPrimCat,$id));
							}
							$sql->query("UPDATE `%s` SET `categories`='%s' WHERE `id`=%d",Array($updateTable,$catStr,$id));
							$vars->delete('cache_categories_'.$c);
							$vars->delete('cache_'.$updateTable.'_'.$res['id']);
							$json->add('success',true);
							$json->add('categories',$newCats);
							$json->add('primcat',$newPrimCat);
						}else{
							$this->warning('not found');
						}
					}else{
						$this->warning('invalid id');
					}
				}
			}else{
				$this->warning('invalid id');
			}
		}else{
			$this->warning('permission denied');
		}
	}
	public function primCat($id,$primcat){
		global $sql,$json,$vars,$you;
		if($you->isAdmin()){
			if(is_string($id) && strlen($id)>=2){
				$updateTable = NULL;
				switch($id[0]){
					case 'o':
						$updateTable = 'objects';
						break;
					case 'c':
						$updateTable = 'categories';
						break;
					default:
						$this->warning('invalid id');
				}
				if($updateTable!==NULL){
					$id = substr($id,1);
					if(can_int($id) && can_int($primcat)){
						$res = $sql->query("SELECT `id` FROM `%s` WHERE `id`=%d",Array($updateTable,$id),0);
						if($res['id']!==NULL){
							$res2 = $sql->query("SELECT `id` FROM `categories` WHERE `id`=%d",Array($primcat),0);
							if($res2['id']!==NULL){
								$sql->query("UPDATE `%s` SET `primcat`=%d WHERE `id`=%d",Array($updateTable,$primcat,$id));
								$json->add('success',true);
							}else{
								$this->warning('not found');
							}
						}else{
							$this->warning('not found');
						}
					}else{
						$this->warning('invalid id');
					}
				}
			}else{
				$this->warning('invalid id');
			}
		}else{
			$this->warning('permission denied');
		}
	}
	public function getUncategorized(){
		global $sql,$json;
		$retCats = Array();
		$fetchCats = $sql->query("SELECT `name`,`id` FROM `categories` WHERE NOT `categories` LIKE '%s'",Array('%[%'));
		foreach($fetchCats as $c){
			if($c['id']!==NULL){
				$retCats[] = Array(
					'name' => $c['name'],
					'id' => (int)$c['id']
				);
			}
		}
		$json->add('uncatCats',$retCats);
	}
	public function delete($id){
		global $sql,$json,$vars,$you;
		if($you->isAdmin()){
			if(can_int($id) && $id!=1){
				$fetchCat = $sql->query("SELECT `id` FROM `categories` WHERE `id`=%d",Array((int)$id),0);
				if($fetchCat['id'] !== NULL){
					$this->get((int)$id);
					foreach($json->getIndex('categories') as $c){
						$vars->delete('cache_categories_'.$c['id']);
					}
					foreach($json->getIndex('objects') as $objs){
						foreach($objs['objects'] as $o){
							$this->removeFrom('o'.$o['id'],(int)$id);
						}
					}
					foreach($json->getIndex('subcategories') as $c){
						$this->removeFrom('c'.$c['id'],(int)$id);
					}
					
					
					$vars->delete('cache_categories_'.$id);
					$json->deleteIndex('objects');
					$json->deleteIndex('subcategories');
					$json->deleteIndex('categories');
					$json->deleteIndex('name');
					
					$sql->query("DELETE FROM `categories` WHERE `id`=%d",Array((int)$id));
					$json->add('success',true);
					
					
				}else{
					$this->warning('Category not found');
				}
			}else{
				$this->warning('invalid id');
			}
		}else{
			$this->warning('permission denied');
		}
	}
}
$category = new Category();

class Search{
	private function searchVars($s){
		global $sql,$json;
		$searchS = '%'.strtolower($s).'%';
		
		$res = $sql->query("SELECT `id` FROM `variables` WHERE `dispValue` LIKE '%s'",Array($searchS));
		
		$resVars = Array();
		foreach($res as $r){
			if($r['id']!==NULL){
				$resVars[] = $r['id'];
			}
		}
		$json->add('vars',$resVars);
	}
	private function fetchObjs($a){
		global $sql,$json,$object;
		$addQuery = '';
		foreach($a as $i){
			if(can_int($i)){
				$addQuery .= '\\"varId\\":'.$i.'[^0-9]|';
			}
		}
		if($addQuery != ''){
			$addQuery = substr($addQuery,0,strlen($addQuery)-1);
			$res = $sql->query("
				SELECT `id`,`refId` FROM `objects` WHERE
					`value` REGEXP '%s'
				",Array($addQuery));
			$resObjs = Array();
			
			$refIds = Array();
			$objsToGiveOut = Array();
			
			foreach($res as $obj){
				if($obj['id']!==NULL){
					if(!isset($refIds[$obj['refId']])){
						$res = $sql->query("SELECT `name` FROM `objectTypes` WHERE `id`=%d",Array((int)$obj['refId']),0);
						$refIds[$obj['refId']] = $res['name'];
						$objsToGiveOut[$res['name']] = Array(
							'id' => (int)$obj['refId'],
							'objects' => Array()
						);
					}
					$object->get((int)$obj['id']);
					
					$objsToGiveOut[$refIds[$obj['refId']]]['objects'][] = $json->getIndex('object');
					$json->deleteIndex('object');
					$json->deleteIndex('categories');
				}
			}
			$json->deleteIndex('object');
			$json->deleteIndex('categories');
			$json->add('objects',$objsToGiveOut);
		}
	}
	private function searchCats($s){
		global $sql,$json;
		$searchS = '%'.strtolower($s).'%';
		
		$res = $sql->query("SELECT `id`,`name` FROM `categories` WHERE `name` LIKE '%s'",Array($searchS));
		
		if($res[0]['id']!==NULL){
			$json->add('categories',$res);
		}else{
			$json->add('categories',array());
		}
	}
	public function go($s){
		global $json;
		$json->add('search',$s);
		$this->searchVars($s);
		$this->fetchObjs($json->getIndex('vars'));
		$json->deleteIndex('vars');
		$this->searchCats($s);
	}
}
$search = new Search();

if(isset($_GET['var'])){
	$variable->get($_GET['var']);
	
}elseif(isset($_GET['obj'])){
	$object->get($_GET['obj']);
}elseif(isset($_GET['objcsv'])){
	$object->getCSV($_GET['objcsv']);
}elseif(isset($_GET['cat'])){
	$category->get($_GET['cat']);
}elseif(isset($_GET['catcsv'])){
	$category->getCSV($_GET['catcsv']);
}elseif(isset($_GET['editVar'])){
	$variable->getEditInfo($_GET['editVar']);
}elseif(isset($_GET['editObj'])){
	$object->getEditInfo($_GET['editObj']);
}elseif(isset($_GET['delObj'])){
	$object->delete($_GET['delObj']);
}elseif(isset($_GET['saveVar'])){
	if(isset($_POST['value'])){
		$variable->save($_GET['saveVar'],$_POST['value']);
	}else{
		$json->addError('Missing required field');
	}
}elseif(isset($_GET['newObj'])){
	if(!isset($_GET['cats'])){
		$_GET['cats'] = '[]';
	}
	$object->newObj($_GET['newObj'],json_decode($_GET['cats'],true));
}elseif(isset($_GET['addPicklistItem'])){
	if(!isset($_POST['item'])){
		$variable->addPicklistItem($_GET['addPicklistItem'],$_POST['value']);
	}else{
		$json->addError('Missing required field');
	}
}elseif(isset($_GET['newCat'])){
	if($_GET['newCat']!=''){
		$category->newCat($_GET['newCat']);
	}else{
		$json->addError('Category name not specified');
	}
}elseif(isset($_GET['getCatHint'])){
	$category->getHint($_GET['getCatHint']);
}elseif(isset($_GET['addCat'])){
	if(isset($_GET['id'])){
		$category->addTo($_GET['id'],$_GET['addCat']);
	}else{
		$json->addError('Missing required field');
	}
}elseif(isset($_GET['remCat'])){
	if(isset($_GET['id'])){
		$category->removeFrom($_GET['id'],$_GET['remCat']);
	}else{
		$json->addError('Missing required field');
	}
}elseif(isset($_GET['primCat'])){
	if(isset($_GET['id'])){
		$category->primCat($_GET['id'],$_GET['primCat']);
	}else{
		$json->addError('Missing required field');
	}
}elseif(isset($_GET['search'])){
	if(is_string($_GET['search']) && $_GET['search']!==''){
		$search->go($_GET['search']);
	}else{
		$json->addError('invalid search');
	}
}elseif(isset($_GET['listVarTypes'])){
	$variable->listTypes();
}elseif(isset($_GET['editVarType'])){
	$variable->getEditVarTypeInfo($_GET['editVarType']);
}elseif(isset($_GET['saveVarType'])){
	if(isset($_POST['name']) && isset($_POST['tooltip']) && isset($_POST['value'])){
		$variable->saveVarType($_GET['saveVarType'],$_POST['name'],$_POST['tooltip'],$_POST['value']);
	}else{
		$json->addError('Missing required field');
	}
}elseif(isset($_GET['newVarType'])){
	$variable->newVarType($_GET['newVarType']);
}elseif(isset($_GET['listObjTypes'])){
	$object->listObjTypes();
}elseif(isset($_GET['editObjType'])){
	$object->getEditObjTypeInfo($_GET['editObjType']);
}elseif(isset($_GET['saveObjType'])){
	if(isset($_POST['name']) && isset($_POST['value'])){
		$object->saveObjType($_GET['saveObjType'],$_POST['name'],$_POST['value']);
	}else{
		$json->addError('Missing required field');
	}
}elseif(isset($_GET['newObjType'])){
	$object->newObjType();
}elseif(isset($_GET['uncatCats'])){
	$category->getUncategorized();
}elseif(isset($_GET['uncatObjs'])){
	$object->getUncategorized();
}elseif(isset($_GET['delCat'])){
	$category->delete($_GET['delCat']);
}elseif(isset($_GET['catname'])){
	if(isset($_GET['name'])){
		$category->editName($_GET['catname'],$_GET['name']);
	}else{
		$json->addError('Missing required field');
	}
}elseif(isset($_GET['upload'])){
	$images->upload();
}elseif(isset($_GET['listFiles'])){
	$images->listFiles();
}elseif(isset($_GET['login'])){
	if(isset($_POST['password'])){
		$you->login($_GET['login'],$_POST['password']);
	}else{
		$json->addError('Missing required field');
	}
}elseif(isset($_GET['logout'])){
	$you->logout();
}elseif(isset($_GET['newUser'])){
	if(isset($_POST['password'])){
		$you->newUser($_GET['newUser'],$_POST['password']);
	}else{
		$json->addError('Missing required field');
	}
}elseif(isset($_GET['editPwd'])){
	if(isset($_POST['password'])){
		$you->editPwd($_GET['editPwd'],$_POST['password']);
	}else{
		$json->addError('Missing required field');
	}
}elseif(isset($_GET['userinfo'])){
	$you->getUserInfo();
}elseif(isset($_GET['remadmin'])){
	$you->remAdmin($_GET['id']);
}elseif(isset($_GET['addadmin'])){
	$you->addAdmin($_GET['id']);
}else{
	$json->addWarning('unknown operation');
}
$json->add('sqlqueries',$sql->getQueryNum());
echo $json->get();
?>