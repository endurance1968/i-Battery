<?php

class Battery_API {
    private $token_file	= './access/token.json';
    private $auth_file	= './access/auth.json';
	private $stats_file	= './../data/appstats.txt';

    private $auth_api = 'https://customer.bmwgroup.com/gcdm/oauth/authenticate';
    private $vehicle_api = 'https://www.bmw-connecteddrive.de/api/vehicle';

    private $auth;
    private $token;
    private $json;
	
    function __construct () {
        $this->check_security();

        $this->auth 		= $this->get_auth_data();
		// to check if user/pwd were correctly read
		//error_log("User:".$this->auth->username);
		//error_log("PWD:".$this->auth->password);
        $this->token 		= $this->get_token();
        $this->json 		= $this->get_vehicle_data();

        $this->send_response_json();
    }

    function check_security() {
		//error_log("checksecurity: ");
		//error_log($_SERVER['HTTP_REFERER']);
        if ( empty( $_SERVER['HTTP_REFERER'] ) OR strcmp( parse_url( $_SERVER['HTTP_REFERER'], PHP_URL_HOST ), $_SERVER['SERVER_NAME'] ) !== 0 ) {
            http_response_code( 403 ) && exit;
        }
    }

    function get_auth_data() {
        return json_decode(
            @file_get_contents(
                $this->auth_file
            )
        );
    }

    function cache_remote_token( $token_data ) {
        file_put_contents(
            $this->token_file,
            json_encode( $token_data )
        );
    }

    function get_cached_token() {
        return json_decode(
            @file_get_contents(
                $this->token_file
            )
        );
    }

    function get_token() {
        // Get cached token
        if ( $cached_token_data = $this->get_cached_token() ) {
            if ( $cached_token_data->expires > time() ) {
                $token = $cached_token_data->token;
            }
        }
        // Get remote token
        if ( empty( $token ) ) {
            $token_data = $this->get_remote_token();
            $token = $token_data->token;

            $this->cache_remote_token( $token_data );
        }

        return $token;
    }

    function get_remote_token() {
        // Init cURL
        $ch = curl_init();

        // Set cURL options
        curl_setopt( $ch, CURLOPT_URL, $this->auth_api );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_HEADER, true );
        curl_setopt( $ch, CURLOPT_NOBODY, true );
        curl_setopt( $ch, CURLOPT_COOKIESESSION, true );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Content-Type: application/x-www-form-urlencoded' ) );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, 'username=' . urlencode( $this->auth->username) . '&password=' . urlencode( $this->auth->password) . '&client_id=dbf0a542-ebd1-4ff0-a9a7-55172fbfce35&redirect_uri=https%3A%2F%2Fwww.bmw-connecteddrive.com%2Fapp%2Fdefault%2Fstatic%2Fexternal-dispatch.html&response_type=token&scope=authenticate_user%20fupo&state=eyJtYXJrZXQiOiJkZSIsImxhbmd1YWdlIjoiZGUiLCJkZXN0aW5hdGlvbiI6ImxhbmRpbmdQYWdlIn0&locale=DE-de' );
		//curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false);
		
        // Exec curl request
        $response = curl_exec( $ch );

		if(empty($response) || $response === false || !empty(curl_error($ch))) {
				error_log('Empty answer from Bearerinterface:'.curl_error($ch));
		}     
        // Close connection
        curl_close( $ch );

        // Extract token
        preg_match( '/access_token=([\w\d]+).*token_type=(\w+).*expires_in=(\d+)/', $response, $matches );

        // Check token type
        if ( empty( $matches[2] ) OR $matches[2] !== 'Bearer' ) {
			error_log("No remote token received - username or password might be wrong\n".$response);
            http_response_code( 424 ) && exit;
        }

        return (object) array(
            'token' => $matches[1],
            'expires' => time() + $matches[3]
        );
    }

	//
	// this function removes unwanted characters from a strin to be json decoded
	//
	function prepare_str_for_jsondecode($str){	
		// This will remove unwanted characters.
		// Check http://www.php.net/chr for details
		for ($i = 0; $i <= 31; ++$i) { 
			$str = str_replace(chr($i), "", $str); 
		}
		$str = str_replace(chr(127), "", $str);
		// This is the most common part
		// Some file begins with 'efbbbf' to mark the beginning of the file. (binary level)
		// here we detect it and we remove it, basically it's the first 3 characters 
		if (0 === strpos(bin2hex($str), 'efbbbf')) {
			$str = substr($str, 3);
		}
		return $str;
	}
	
    function get_vehicle_data() {
		$json=null;
		$resp_array_1=null;
		$resp_array_2=null;
        // Init cURL
        $ch_1 = curl_init();
        $ch_2 = curl_init();

        // Set cURL options
        curl_setopt( $ch_1, CURLOPT_URL, $this->vehicle_api . '/dynamic/v1/' . $this->auth->vehicle . '?offset=-60' );
        curl_setopt( $ch_1, CURLOPT_HTTPHEADER, array( 'Content-Type: application/json' , 'Authorization: Bearer ' . $this->token ) );
        curl_setopt( $ch_1, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch_1, CURLOPT_FOLLOWLOCATION, true );
		//curl_setopt( $ch_1, CURLOPT_SSL_VERIFYPEER, false);
        
		curl_setopt( $ch_2, CURLOPT_URL, $this->vehicle_api . '/navigation/v1/' . $this->auth->vehicle );
        curl_setopt( $ch_2, CURLOPT_HTTPHEADER, array( 'Content-Type: application/json' , 'Authorization: Bearer ' . $this->token ) );
        curl_setopt( $ch_2, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch_2, CURLOPT_FOLLOWLOCATION, true );
		//curl_setopt( $ch_2, CURLOPT_SSL_VERIFYPEER, false);
        
		// Build the multi-curl handle
        $mh = curl_multi_init();
        curl_multi_add_handle( $mh, $ch_1 );
        curl_multi_add_handle( $mh, $ch_2 );

        // Execute all queries simultaneously
        $running = null;
        do {
            curl_multi_exec( $mh, $running );
        } while ( $running );

        // Close the handles
        curl_multi_remove_handle( $mh, $ch_1 );
        curl_multi_remove_handle( $mh, $ch_2 );
        curl_multi_close( $mh );

        // all of our requests are done, we can now access the results
		// Response 1 should be something like we need only the attributeMap from it
		/*
		{
		  "attributesMap" : {
			"updateTime_converted" : "04.02.2017 15:42",                                          
			"condition_based_services" : "00032,OK,2017-04,;00003,OK,2018-03,;00017,OK,2018-03,",
			"door_lock_state" : "SECURED",                                                        // "SECURED", LOCKED, SELECTIVELOCKED, OPEN, ...
			"vehicle_tracking" : "1",
			"Segment_LastTrip_time_segment_end_formatted_time" : "15:35",
			"lastChargingEndReason" : "UNKNOWN",
			"door_passenger_front" : "CLOSED",
			"check_control_messages" : "",
			"chargingHVStatus" : "INVALID",
			"beMaxRangeElectricMile" : "75.0",
			"lights_parking" : "OFF",
			"connectorStatus" : "DISCONNECTED",
			"kombi_current_remaining_range_fuel" : "0.0",
			"window_passenger_front" : "CLOSED",
			"beRemainingRangeElectricMile" : "63.0",
			"mileage" : "42768",
			"door_driver_front" : "CLOSED",
			"updateTime" : "04.02.2017 14:42:55 UTC",
			"window_passenger_rear" : "CLOSED",
			"Segment_LastTrip_time_segment_end" : "04.02.2017 15:35:00 UTC",
			"remaining_fuel" : "0.0",
			"updateTime_converted_time" : "15:42",
			"window_driver_front" : "CLOSED",
			"chargeNowAllowed" : "NOT_ALLOWED",
			"unitOfCombustionConsumption" : "l/100km",
			"beMaxRangeElectric" : "122.0",
			"soc_hv_percent" : "78.2",
			"single_immediate_charging" : "isUnused",
			"beRemainingRangeElectric" : "102.0",
			"heading" : "0",
			"Segment_LastTrip_time_segment_end_formatted" : "04.02.2017 15:35",
			"updateTime_converted_timestamp" : "1486222975000",
			"gps_lat" : "48.95617",
			"window_driver_rear" : "CLOSED",
			"lastChargingEndResult" : "UNKNOWN",
			"trunk_state" : "CLOSED",
			"hood_state" : "CLOSED",
			"chargingLevelHv" : "86.0",
			"lastUpdateReason" : "VEHCSHUTDOWN_SECURED",
			"lsc_trigger" : "VEHCSHUTDOWN_SECURED",
			"unitOfEnergy" : "kWh",
			"Segment_LastTrip_time_segment_end_formatted_date" : "04.02.2017",
			"prognosisWhileChargingStatus" : "NOT_NEEDED",
			"beMaxRangeElectricKm" : "122.0",
			"unitOfElectricConsumption" : "kWh/100km",
			"Segment_LastTrip_ratio_electric_driven_distance" : "100",
			"head_unit_pu_software" : "07/14",
			"head_unit" : "NBT",
			"chargingSystemStatus" : "NOCHARGING",
			"door_driver_rear" : "CLOSED",
			"charging_status" : "NOCHARGING",
			"beRemainingRangeElectricKm" : "102.0",
			"gps_lng" : "9.470959",
			"door_passenger_rear" : "CLOSED",
			"updateTime_converted_date" : "04.02.2017",
			"chargingLogicCurrentlyActive" : "NOT_CHARGING",
			"unitOfLength" : "km",
			"battery_size_max" : "21000"
		  },
		  "vehicleMessages" : {
			"ccmMessages" : [ ],
			"cbsMessages" : [ {
			  "description" : "Nächste gesetzliche Fahrzeuguntersuchung zum angegebenen Termin.",
			  "text" : "§ Fahrzeuguntersuchung",
			  "id" : 32,
			  "status" : "OK",
			  "messageType" : "CBS",
			  "date" : "2017-04"
			}, {
			  "description" : "Nächster Wechsel spätestens zum angegebenen Termin.",
			  "text" : "Bremsflüssigkeit",
			  "id" : 3,
			  "status" : "OK",
			  "messageType" : "CBS",
			  "date" : "2018-03"
			}, {
			  "description" : "Nächste Sichtprüfung nach der angegebenen Fahrstrecke oder zum angegebenen Termin.",
			  "text" : "Fahrzeug-Check",
			  "id" : 17,
			  "status" : "OK",
			  "messageType" : "CBS",
			  "date" : "2018-03"
			} ]
		  }
		}
		*/
		// response 2 should look like
		/*
		{
		  "latitude" : 48.95617,
		  "longitude" : 9.470959,
		  "isoCountryCode" : "DEU",
		  "auxPowerRegular" : 1.4,
		  "auxPowerEcoPro" : 1.2,
		  "auxPowerEcoProPlus" : 0.4,
		  "soc" : 15.717000007629395,
		  "socMax" : 18.6,
		  "eco" : "609,450,387,387,417,48c,595,623,6b2,7d0,8ee",
		  "norm" : "6ef,4f4,3ad,3ad,417,48c,636,6d5,774,8b2,9f0",
		  "ecoEv" : "609,450,387,387,417,48c,595,623,6b2,7d0,8ee",
		  "normEv" : "6ef,4f4,3ad,3ad,417,48c,636,6d5,774,8b2,9f0",
		  "vehicleMass" : "1260",
		  "kAccReg" : "2412000",
		  "kDecReg" : "3240000",
		  "kAccEco" : "2520000",
		  "kDecEco" : "3240000",
		  "kUp" : "3132000",
		  "kDown" : "3384000",
		  "driveTrain" : "bev_ohne_rex",
		  "pendingUpdate" : false,
		  "vehicleTracking" : true
		}
		*/		
        
		// evaluate response of navigation interface
		$response_1 = curl_multi_getcontent( $ch_1 );
		if(empty($response_1)) {
				error_log('Empty answer from navigation interface: '.curl_error($ch_1));
		} else {
			// decode response
			$resp_array_1=json_decode( $response_1, true )['attributesMap'];
			$jsonerror=json_last_error();
			switch($jsonerror) {
				case JSON_ERROR_NONE:
					break;
				case JSON_ERROR_DEPTH:
					error_log(' JSON1 - Maximale Stacktiefe überschritten'.">".$response_1."<");
					break;
				case JSON_ERROR_STATE_MISMATCH:
					error_log(' JSON1 - Unterlauf oder Nichtübereinstimmung der Modi'.">".$response_1."<");
					break;
				case JSON_ERROR_CTRL_CHAR:
					error_log(' JSON1 - Unerwartetes Steuerzeichen gefunden'.">".$response_1."<>".$ch_1."<");
					break;
				case JSON_ERROR_SYNTAX:
					error_log(' JSON1 - Syntaxfehler, ungültiges JSON'.">".$response_1."<");
					break;
				case JSON_ERROR_UTF8:
					error_log(' JSON1 - Missgestaltete UTF-8 Zeichen, möglicherweise fehlerhaft kodiert'.">".$response_1."<");
					break;
				default:
					error_log(' JSON1 - Unbekannter Fehler'.">".$response_1."<");
					break;
			}			
		}
		
		// evalutae response of application interface
		$response_2 = curl_multi_getcontent( $ch_2 );
		if(empty($response_2)) {
				error_log('Empty answer from application interface: '.curl_error($ch_2));
		} else {
			$resp_array_2=json_decode( $response_2, true);
			$jsonerror=json_last_error();
			switch($jsonerror) {
				case JSON_ERROR_NONE:
					break;
				case JSON_ERROR_DEPTH:
					error_log(' JSON2 - Maximale Stacktiefe überschritten'.">".$response_2."<");
					break;
				case JSON_ERROR_STATE_MISMATCH:
					error_log(' JSON2 - Unterlauf oder Nichtübereinstimmung der Modi'.">".$response_2."<");
					break;
				case JSON_ERROR_CTRL_CHAR:
					error_log(' JSON2 - Unerwartetes Steuerzeichen gefunden'.">".$response_2."<>".$ch_2."<");
					break;
				case JSON_ERROR_SYNTAX:
					error_log(' JSON2 - Syntaxfehler, ungültiges JSON'.">".$response_2."<");
					break;
				case JSON_ERROR_UTF8:
					error_log(' JSON2 - Missgestaltete UTF-8 Zeichen, möglicherweise fehlerhaft kodiert'.">".$response_2."<");
					break;
				default:
					error_log(' JSON2 - Unbekannter Fehler'.">".$response_2."<");
					break;
			}			
		}
			
        // Merge response
		if(is_array($resp_array_1) && is_array($resp_array_2))
		{
			$json = (object)array_merge($resp_array_1,$resp_array_2);
			//error_log(' JSON - debug output of responses>'.$response_1."<>".$response_2."<");
		} else if(is_array($resp_array_1)) {
			error_log(' JSON - Only array 1>'.$response_1."<>".$response_2."<");
			$json=$resp_array_1;
		} else if(is_array($resp_array_2)) {
			error_log(' JSON - Only array 2>'.$response_1."<>".$response_2."<");
			$json=$resp_array_2;
		} 
	
        return $json;
    }

	function read_last_stats() { 
		// check for empty or not existing file
		if(!file_exists($this->stats_file))
			return " ";
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
	
	function write_last_stats($statsline){
		$initialwriting=0;
		// check for empty or not existing file
		if(!file_exists($this->stats_file) || filesize($this->stats_file)===0){
			//error_log("write_last_stats: initial writing");
			$initialwriting=1;
		}
		$fp = fopen($this->stats_file,'a');
		if($fp)
		{
			if(flock($fp,LOCK_EX))
			{
				if($initialwriting!=0)
					fputs($fp,"DATE_TIME;TIMESTAMP;RANGE;SOC;ACT_SOC;MAX_SOC;REM_TIME;MILEAGE;CHARGEPOWER;CONSUMPTION;LAST_LEG;GPS_LON;GPS_LAT;SOC_HV_PERCENT\r\n");
				fputs($fp,$statsline);
				fflush($fp);
				flock($fp,LOCK_UN);
			} 				
			fclose($fp);
		}
	}
	
	/* extracts data for display and creates a data-string for storage purposes */
	function create_display_data(){
		// DisplayData is returned to index.html
		$display_data=array(
                    'debugString' => "",
					'updateTime' => "01.01.2017 00:00",
                    'Range' => 0,
                    'chargingLevel' => 0,
                    'chargingActive' => 0,
                    'chargingTimeRemaining' => 0,
                    'stateOfCharge' => 0.0,
                    'stateOfChargeMax' => 0.0,
					'doorLockState' => "UNKNOWN",
					'mileage' => 0,		
					'chargingClock' => "00:00",
					'chargingPower' => 0,
					'consumption' => 0,
					'lastLegMileage' => 0,
					'gps_lon' => 0.0,
					'gps_lat' => 0.0,
					'soc_hv_percent' => 100
                );		
		
        $act_updatetime_timestamp=0;
		$last_stateOfCharge=0.0;
		$attributes = $this->json;
				
		//$display_data["debugString"]=$attributes->vehicle_tracking;

		// check if we got json values
		if($attributes==null)
		{
			$display_data["updateTime"]="No data retrieved - car in motion?";
			return $display_data;
		}
		// debug
		//$display_data["doorLockState"]=$attributes;
		// check if there is meaningfull data
		if(!isset($attributes->updateTime_converted))
		{
			$display_data["updateTime"]="No valid data retrieved - car in motion?";
			return $display_data;
		}
		
		$timestamp=intval($attributes->updateTime_converted_timestamp/1000);
		$ltime = localtime(time(),true);
		// "tm_isdst" - Ob für das Datum die Sommerzeit zu berücksichtigen ist Positiv wenn Ja, 0 wenn Nein, negativ wenn unbekannt
		$summertime=intval($ltime['tm_isdst']);
		// checks if the webserver (this page) is currently under summertime
		if($summertime>0)
			$display_data["updateTime"] = strftime("%a %d.%m.%Y %H:%M:%S Summer",$timestamp-3600); 
		else
			$display_data["updateTime"] = strftime("%a %d.%m.%Y %H:%M:%S Winter",$timestamp-3600); // hmm hat BMW etwas geändert? nun auch im winter -1h versatz
		
		$act_updatetime_timestamp = $attributes->updateTime_converted_timestamp; // // UTC + timezone but not Summertime as timestamp
		
        $display_data["Range"] = intval( $attributes->beRemainingRangeElectricKm );
        $display_data["chargingLevel"] = intval( $attributes->chargingLevelHv );
        $display_data["chargingActive"] = intval( $attributes->chargingSystemStatus === 'CHARGINGACTIVE' );
		// one digit percent
        $display_data["soc_hv_percent"] = number_format( round( $attributes->soc_hv_percent, 2 ), 2, ',', '.');//intval( $attributes->soc_hv_percent );		

		// remaining charging time is only included while charging so check first to avoid PHP notice
		if(isset($attributes->chargingTimeRemaining))
			$chargingTimeRemaining = intval( $attributes->chargingTimeRemaining );
		else
			$chargingTimeRemaining=0;

		$display_data["chargingClock"] = ($chargingTimeRemaining ? strftime("%a %H:%M",time()+mktime(1,$chargingTimeRemaining,0,1,1,1970)):'--:--' );
		$display_data["chargingTimeRemaining"] = ( $chargingTimeRemaining ? ( date( 'H:i', mktime( 0, $chargingTimeRemaining ) )) : '--:--' );
		
		
        $display_data["stateOfCharge"] = number_format( round( $attributes->soc, 2 ), 2, ',', '.');
		$act_stateOfCharge=$display_data["stateOfCharge"];
        $display_data["stateOfChargeMax"] = number_format( round( $attributes->socMax, 2 ), 2, ',', '.');

		
		$display_data["doorLockState"]=$attributes->door_lock_state;
		/*
		$doorLockState = intval( $attributes->door_lock_state === 'SECURED' || $attributes->door_lock_state === 'LOCKED');
		if($doorLockState == '1')
			$display_data["doorLockState"]='CLOSED';
		else
			$display_data["doorLockState"]='OPEN';
		*/
		$display_data["mileage"] = intval( $attributes->mileage );
		// check if we got GPS data from the car
		if (property_exists($attributes,'gps_lng')){
			$display_data["gps_lon"]= $attributes->gps_lng;
			$display_data["gps_lat"]= $attributes->gps_lat;	 
		}
		
		$actline  = $display_data["updateTime"].";".$act_updatetime_timestamp.";".$display_data["Range"].";".$display_data["chargingLevel"].";".$display_data["stateOfCharge"].";".$display_data["stateOfChargeMax"].";".$display_data["chargingTimeRemaining"].";".$display_data["mileage"];
		//$display_data["debugString"]=$actline;
		$lastline = $this->read_last_stats();
		if($lastline!=" " && $lastline!='')
		{
			$lastpower=0.0;
			
			// extract the last and actual values
			list($last_updatetime,$last_updatetime_timestamp,$last_electricRange,$last_chargingLevel,$last_stateOfCharge,$last_stateOfChargeMax,$last_chargingTimeRemaining,$last_mileage,$last_chargingpower,$last_consumption,$last_lastLegMileage) 	= str_getcsv($lastline, ';');
			// only to be symmetric to last line
			list($act_updatetime, $act_updatetime_timestamp, $act_electricRange, $act_chargingLevel, $act_stateOfCharge, $act_stateOfChargeMax, $act_chargingTimeRemaining,$act_mileage)    = str_getcsv($actline,  ';');
			$display_data["consumption"]=$last_consumption;
			// from german to english number format
			$last_stateOfCharge=str_replace(',','.',$last_stateOfCharge);
			$act_stateOfCharge=str_replace(',','.',$act_stateOfCharge);			
			// calc soc change
			$socchange=$act_stateOfCharge-$last_stateOfCharge;
			$elapsedtime=$act_updatetime_timestamp-$last_updatetime_timestamp;
			$lastLegMileage=$act_mileage-$last_mileage;
			$display_data["lastLegMileage"]=$lastLegMileage;
			
			// check if we got updated data from BMW server
			if($elapsedtime!=0)
			{
				// convert to hours
				$elapsedtime=($elapsedtime/1000)/3600; 
				
				// caculate charging
				$display_data["chargingPower"]=$socchange/$elapsedtime;
				// cut off after x digits
				if($display_data["chargingPower"]>10)
					$display_data["chargingPower"]=round($display_data["chargingPower"],1);
				else
					$display_data["chargingPower"]=round($display_data["chargingPower"],2);
				if($display_data["chargingPower"]<=0)
					if($last_chargingpower>0)
						$display_data["chargingPower"]=$last_chargingpower;
					else
						$display_data["chargingPower"]='-.--';
				
				// calculate consumption
				if($lastLegMileage>0)
				{
					$display_data["consumption"]=(($socchange*(-1))/$lastLegMileage)*100;
					if($display_data["consumption"]>10)
						$display_data["consumption"]=round($display_data["consumption"],1);
					else
						$display_data["consumption"]=round($display_data["consumption"],2);
					
					if($display_data["consumption"]<=0)
					{
						if($last_consumption>0)
							$display_data["consumption"]=$last_consumption;
						else
							$display_data["consumption"]='-.--';
					}					
				}
				else
				{
					if($socchange<0)
					{
						// eg. while heating without driving
						$display_data["consumption"]=99.9;
					}
					$display_data["lastLegMileage"]=$last_lastLegMileage;	
				}
								
				$actline  = $actline.";".$display_data["chargingPower"].";".$display_data["consumption"].";".$display_data["lastLegMileage"].";".$display_data["gps_lon"].";".$display_data["gps_lat"].";".$display_data["soc_hv_percent"]."\r\n";					
			}
			else
			{
				if($last_consumption>0)
					$display_data["consumption"]=$last_consumption;
				if($last_chargingpower>0)
					$display_data["chargingPower"]=$last_chargingpower;
				if($lastLegMileage<=0)
					$display_data["lastLegMileage"]=$last_lastLegMileage;					
				// should not be needed as if elapsedtime ==0 SOC should also be unchanged and no new line will be written to stats file
				// real experience: happens sometimes directly after connecting the car to the charging station
				$actline  = $actline.";".$display_data["chargingPower"].";".$display_data["consumption"].";".$display_data["lastLegMileage"].";".$display_data["gps_lon"].";".$display_data["gps_lat"].";".$display_data["soc_hv_percent"]."\r\n";
			}
		}
		else
			$actline  = $actline.";--.--;--.--;-".";".$display_data["gps_lon"].";".$display_data["gps_lat"].";".$display_data["soc_hv_percent"]."\r\n";
		
		//simple debug output to GUI
		if($display_data["debugString"]!="")
			$display_data["doorLockState"]='>'.$display_data["debugString"].'<';
		
		// log changes (only if SOC has really be changed)
		if($lastline!=$actline && $act_stateOfCharge!=$last_stateOfCharge && $display_data["mileage"]!=0)
		{
			$this->write_last_stats($actline);
		}		
		return $display_data;
	}
	
	
    function send_response_json() {
        $display_data=$this->create_display_data();
		
        // Send Header
        header('Access-Control-Allow-Origin: https://' . $_SERVER['SERVER_NAME'] );
        header('Content-Type: application/json; charset=utf-8');

        // return json encoded string
		// example
		// $arr = array('a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5);
		// echo json_encode($arr);
		// would create the following string: {"a":1,"b":2,"c":3,"d":4,"e":5}
        die(json_encode($display_data));
    }
}


new Battery_API();
?>