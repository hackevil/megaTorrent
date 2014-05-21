<?php
include_once('inc_mega.php');
/* 
 Config
 */
 $GLOBALS['email'] = 'MEGA_EMAIL';
$GLOBALS['password'] = 'MEGA_PASSWD';


// Check args
if($argc < 2){showError();}
if(!preg_match('/^magnet:.*?xt=[^:]+:[^:]+:([^&]+)/',$argv[1],$m)){showError();}
$magnet = $argv[1];
$magnetHash = $m[1];

if(file_exists('/tmp/torrent-stream/'.$magnetHash)){
	echo 'Deleting previous torrent download',PHP_EOL;
	rrmdir('/tmp/torrent-stream/'.$magnetHash);
}

echo 'Downloading...',PHP_EOL;
$cmd = 'node torrent-downloader/index.js "'.$magnet.'"';
$filename = trim(shell_exec($cmd));
echo 'File: ',$filename,PHP_EOL;

if(!file_exists($filename)){echo 'ERROR GETTING THE FILE';exit;}

// Upload file
echo 'Uploading...',PHP_EOL;
$encodedFilename = $filename.'.enc';

if(!file_exists('./sid')){
	login($email,$password);

	file_put_contents('./sid',$sid);
	file_put_contents('./master_key',json_encode($master_key));
}

$seqno = 1;
$sid = file_get_contents('./sid');
$master_key = json_decode(file_get_contents('./master_key'),true);

// Get root Id
$files = api_req(array('a' => 'f', 'c' => 1));
if(!is_object($files)){echo 'ERROR GETTING FILE LIST',PHP_EOL;exit;}
foreach($files->f as $file){if($file->t == 2){$root_id = $file->h;}}


$fileSize = filesize($filename);
$ul_url = api_req(array('a'=>'u','s'=>$fileSize));
$ul_url = $ul_url->p;
if(!$ul_url){echo 'ERROR GETTING UPLOAD URL',PHP_EOL;exit;}


$ul_key = array(0,0,0,0,0,0);
for($i = 0;$i < 6;$i++){$ul_key[$i] = rand(0,0xFFFFFFFF);}

$encKey = a32_to_str(array_slice($ul_key,0,4));
$encIv = a32_to_str(array($ul_key[4],$ul_key[5],0,0));
$encKeyHex = bin2hex($encKey);
$encIvHex = bin2hex($encIv);

// Encode file
$cmd = 'openssl enc -d -aes-128-ctr -K '.$encKeyHex.' -iv '.$encIvHex.' -in "'.$filename.'" > "'.$encodedFilename.'"';
shell_exec($cmd);

$handle = sendFile($ul_url,$encodedFilename);

$data_mac = cbc_mac_file($filename, array_slice($ul_key, 0, 4), array_slice($ul_key, 4, 2));
$meta_mac = array($data_mac[0] ^ $data_mac[1], $data_mac[2] ^ $data_mac[3]);
$attributes = array('n' => basename($filename));
$enc_attributes = enc_attr($attributes, array_slice($ul_key, 0, 4));
$key = array($ul_key[0] ^ $ul_key[4], $ul_key[1] ^ $ul_key[5], $ul_key[2] ^ $meta_mac[0], $ul_key[3] ^ $meta_mac[1], $ul_key[4], $ul_key[5], $meta_mac[0], $meta_mac[1]);
$uploadedFile = api_req(array('a'=>'p','t'=>$root_id,'n'=>array(array('h'=>$handle,'t'=>0,'a'=>base64urlencode($enc_attributes),'k'=>a32_to_base64(encrypt_key($key,$master_key))))));

$file = $uploadedFile->f[0];
$publicHandle = api_req(array('a'=>'l','n' => $file->h));
$key = explode(':',$file->k);
$decryptedKey = a32_to_base64(decrypt_key(base64_to_a32($key[1]), $master_key));

$publicLink = 'http://mega.co.nz/#!'.$publicHandle.'!'.$decryptedKey;
echo 'Public link: ',$publicLink,PHP_EOL;

// Delete files
rrmdir('/tmp/torrent-stream/'.$magnetHash);


function showError(){
	echo 'USAGE: php '.$_SERVER['argv'][0].' MAGNET_LINK',PHP_EOL;
	exit;
}

function rrmdir($dir){ 
	if(is_dir($dir)){ 
		$objects = scandir($dir); 
		foreach($objects as $object) { 
			if($object != '.' && $object != '..'){ 
				if(filetype($dir.'/'.$object) == 'dir'){rrmdir($dir.'/'.$object);}else{unlink($dir.'/'.$object);}
			}
		}
		reset($objects); 
		rmdir($dir); 
	}
}

?>
