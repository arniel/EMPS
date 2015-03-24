<?php
// If the requested URI is a file in the multi-website engine's folder, just send the file using HTTP_Download

$x = explode("?", $_SERVER['REQUEST_URI'], 2);
$uri = $x[0];

$dir = EMPS_SCRIPT_PATH;

$uri = str_replace('../', '/', $uri);

$fname = $dir.$uri;


if(!strstr($uri, ".php") && !strstr($uri, ".sql") && !strstr($uri, "/modules/") && !strstr($uri, "/templates/") && !strstr($uri, "/local/")){
	$go = false;
	
	if(file_exists($fname)){
		if(!is_dir($fname)){
			$go = true;
		}
	}else{
		$fname = EMPS_PATH_PREFIX.$uri;
		$fname = stream_resolve_include_path($fname);
		
		if($fname != false){
			$go = true;
		}
	}
	
	if($go){
		require_once 'MIME/Type.php';		
		
		$type = new MIME_Type();
		$type->useFinfo = false;
		$type->useMimeContentType = false;
		$type->useFileCmd = false;
		
		$content_type = $type->autoDetect($fname);
		
		if(PEAR::isError($content_type)){
			$content_type = "application/x-octetstream";			
		}

		ob_end_clean();
		
		if(file_exists($fname) && !is_dir($fname)){
		
			$fh = fopen($fname, "rb");
			
			if($fh){		
				$size = filesize($fname);
				
				$body = new http\Message\Body($fh);
				$resp = new http\Env\Response;
				
				$resp->setContentType($content_type);
				$resp->setHeader("Content-Length", $size);
				$resp->setHeader("Last-Modified", date("r", filemtime($fname)));
				$resp->setHeader("Expires", date("r", time()+60*60*24*7));
				$resp->setCacheControl("Cache-Control: max-age=".(60*60*24*7));
				$resp->setBody($body);
				$resp->send();			
		
				fclose($fh);
			}
		}

		exit();
	}
}

?>