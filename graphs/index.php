<?php

class Graphic_API {
	private $stats_file	= '../data/appstats.txt';
		
    function __construct () {
		$this->send_response_json();
    }

	/*
	Reads the stats file and converts it into an array
	*/
	function read_stats_csv($file_path){
		$result = array();		
		
		if(!file_exists($this->stats_file))
			return $result;
		if(filesize($this->stats_file)===0)
			return $result;
		
		$file_handle = fopen($file_path, "r");

		if ($file_handle !== FALSE) {
			// reads the columns headers delimited by ";"
			$column_headers = fgetcsv($file_handle,0,';'); 
			foreach($column_headers as $header) {
					$result[$header] = array();
			}
			// read all datasets
			while (($data = fgetcsv($file_handle,0,';')) !== FALSE) {
				$i = 0;
				foreach($result as &$column) {
					// check if the line contains enough data
					if($i<sizeof($data)){
						$column[] = $data[$i];
					}
					$i++;
				}
			}
			fclose($file_handle);
		}
		return $result;
	}
	
	/*
	reads the last line of the stats file
	*/
	function read_last_stats() { 
		// check for empty file
		if(filesize($this->stats_file)===0)
			return " ";
	
		$fp = fopen($this->stats_file, "r"); 

		$pos = -2; 
		$t = " "; 
		if($fp)
		{
			if(flock($fp,LOCK_SH))
			{
				while ($t != "\n") 
				{ 
					 if(fseek($fp, $pos, SEEK_END)!=0)
					 {
						 break;
					 }
					 $t = fgetc($fp); 
					 $pos = $pos - 1; 
				} 
				$t = fgets($fp); 
				flock($fp,LOCK_UN);
			}
			fclose($fp); 
		}
		return $t; 
	} 	
	
	function send_response_json() {
        $result=$this->read_stats_csv($this->stats_file);
		$jsonstring=json_encode($result);
		$jsonerror=json_last_error();
		switch($jsonerror) {
			case JSON_ERROR_NONE:
				break;
			case JSON_ERROR_DEPTH:
				error_log(' JSON_DATA - Maximale Stacktiefe überschritten'.">".$result."<");
				break;
			case JSON_ERROR_STATE_MISMATCH:
				error_log(' JSON_DATA - Unterlauf oder Nichtübereinstimmung der Modi'.">".$result."<");
				break;
			case JSON_ERROR_CTRL_CHAR:
				error_log(' JSON_DATA - Unerwartetes Steuerzeichen gefunden'.">".$result."<");
				break;
			case JSON_ERROR_SYNTAX:
				error_log(' JSON_DATA - Syntaxfehler, ungültiges JSON'.">".$result."<");
				break;
			case JSON_ERROR_UTF8:
				error_log(' JSON_DATA - Missgestaltete UTF-8 Zeichen, möglicherweise fehlerhaft kodiert'.">".$result."<");
				break;
			default:
				error_log(' JSON_DATA - Unbekannter Fehler'.">".$result."<");
				break;
		}			
		//error_log($jsonstring);
		
        // Send Header
        header('Access-Control-Allow-Origin: https://' . $_SERVER['SERVER_NAME'] );
        header('Content-Type: application/json; charset=utf-8');

		die($jsonstring);
    }
}

new Graphic_API();
?>