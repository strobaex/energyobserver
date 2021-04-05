<?php
	//config.json einlesen
	$input = file_get_contents('config.json');
	if ($input) {
		$temp = json_decode($input, true);
		$shellys = json_decode($temp['shellys'], true);
	} else {
		$shellys = array();
	}
	unset($input);
	unset($temp);
	
	//Shelly-Aufrufe zum Auslesen der momentanen Leistung generieren
	$zaehler = 0;
	foreach ($shellys as $single_shelly) {
		if (isset($oldip) && $oldip == $single_shelly['IP'])
			$zaehler++;
		else
			$zaehler = 0;
		$filename = str_replace('.', '', $single_shelly['IP']) . '_' . str_replace(' ', '_', $single_shelly['Name']) . '.html';
		switch ($single_shelly['Shelly-Typ']) {
			case 'SHSW-PM':
					$queries[] = ["http://" . $single_shelly['IP'] . '/status/meters/', $filename, $single_shelly['Shelly-Typ']];
					break;
			default:
					$queries[] = ["http://" . $single_shelly['IP'] . '/meter/' . $zaehler, $filename, $single_shelly['Shelly-Typ']];
		}
		$oldip = $single_shelly['IP'];
	}
	unset($oldip);
	unset($filename);
	unset($single_shelly);
	unset($zaehler);
	
	//Abfrage für jede Shelly durchführen
	foreach ($queries as $single_query) {
		//Speicherdatei einlesen
		$input = file_get_contents($single_query[1]);
		if ($input)
			$data = json_decode($input, true);
		else
			$data = array();
		
		// Aufrufuhrzeit
		$timestamp = time();
		
		//ShellyPlug auslesen
		$level = query($single_query[0], $single_query[2]);
		
		$data[$timestamp] = $level;
		
		$output = json_encode($data);
		
		$result = file_put_contents($single_query[1], $output);
	}
	
	function query($abfrage, $shelly_typ) {
		// create & initialize a curl session
		$curl = curl_init();
		
		// set our url with curl_setopt()
		curl_setopt($curl, CURLOPT_URL, $abfrage);
		
		// return the transfer as a string, also with setopt()
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		
		// curl_exec() executes the started curl session
		// $output contains the output string
		$output = curl_exec($curl);
		
		// close curl resource to free up system resources
		// (deletes the variable made by curl_init)
		curl_close($curl);
		
		$values = json_decode($output,true);
		switch ($shelly_typ) {
			case "SHSW-PM":
				$power = $values['meters'][0]['power'];
				break;
			default:
				$power = $values['power'];
		}
		return ($power);
	}