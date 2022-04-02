<?php
	//config.json schreiben
	if (isset($_POST['config'])) {
		$config['price'] = json_decode($_POST['price']);
		$config['shellys'] = $_POST['config'];
		$config = json_encode($config);
		file_put_contents('config.json', $config);
		exit();
	}
	
	//Shelly-Daten auslesen
	if (isset($_POST['IP'])) {
		$url = 'http://' . $_POST['IP'] . '/settings';
		$result = query($url);
		//Mehrere Strommesser?
		for ($i = 0; $i < $result['device']['num_meters']; $i++) {
			$config[$i]['Shelly-Typ'] = $result['device']['type'];
			$config[$i]['Name'] = $result['relays'][$i]['name'];
		}
		echo json_encode($config);
		exit();
	}
	
	//Datenfile löschen
	if (isset($_POST['delete'])) {
		$delete_shelly = preg_split("/[\t]/", $_POST['delete']);
		$filename = str_replace('.', '', $delete_shelly[0]) . '_' . str_replace(' ', '_', $delete_shelly[2]) . '.html';
		unlink($filename);
		exit();
	}
	
	//config.json einlesen
	$input = file_get_contents('config.json');
	if ($input) {
		$temp = json_decode($input, true);
		$shellys = json_decode($temp['shellys'], true);
		$price = floatval(json_decode($temp['price']));
	} else {
		$shellys = array();
		$price = 0.00;
	}
	unset($input);
	unset($temp);
	
	//filenames generieren
	foreach ($shellys as $key => $single_shelly) {
		$shellys[$key]['filename'] = str_replace('.', '', $single_shelly['IP']) . '_' . str_replace(' ', '_', $single_shelly['Name']) . '.html';
	}
	
	$temp = in_array($_POST['filename'],array_column($shellys, 'filename'));
 
	if (isset($_POST['filename']) && $temp == true)
		$filename = $_POST['filename'];
	else if (isset($_POST['anzeige']))
		$filename = substr(strstr($_POST['anzeige'], "_"), 1);
	else $filename = $shellys[0]['filename'];
	
	
	//Speicherdatei einlesen
	$input = file_get_contents($filename);
	if ($input)
		$data = json_decode($input, true);
	else
		$data = array();
	unset($input);
	
	$einheit = "Watt";
	$labels = array_keys($data);
	$values = array_values($data);
	
	// Tageswerte berechnen
	$startdatum = min($labels);
	$enddatum = max($labels);
	$distance = (($enddatum - $startdatum) / count($labels)) / 60 / 60; //Wert in h umgerechnet
	//Über die einzelnen Tage iterieren und Summe der Verbräuche berechnen
	$tageswerte = array();
	$midnight = strtotime("midnight", $startdatum);
	while ($midnight < $enddatum) {
		$midnight_tomorrow = strtotime("midnight tomorrow", $midnight);
		$energy = 0.0;
		$zaehler = 0;
		foreach ($data as $key => $wert) {
			if ($key >= $midnight && $key < $midnight_tomorrow) {
				//Wattsekunden berechnen aus Leistung in $wert und Dauer des Stromflusses aus $distance
				$energy += $wert / 1000 * $distance; //Wert in KW umgerechnet, dann mit h multipliziert
				$zaehler++;
			}
		}
		//Hochrechnung des Tageswerts, für den ersten und letzten Erfassungstag, da diese nicht vollständig erfasst wurden
        	/*if ($midnight <= $startdatum) {
        		//Erster Erfassungstag
	        	$max_distances = 24 / $distance;
            		$energy = ($energy/$zaehler)*$max_distances;
        	}*/
		/*if ($enddatum <= $midnight_tomorrow) {
			//Letzter Erfassungstag
			$max_distances = 24 / $distance;
			$energy = ($energy/$zaehler)*$max_distances;
		}*/
		$tageswerte[date("d.m.Y", $midnight)] = $energy;
		$midnight = $midnight_tomorrow;
	}
	unset($startdatum);
	unset($enddatum);
	unset($midnight);
	unset($midnight_tomorrow);
	unset($max_distances);
	unset($zaehler);
	unset($energy);
	unset($wert);
	
	//Durchschnitt berechnen
	$temp = $tageswerte;
	switch (count($temp)) {
	    case 0:
	        //Kein Tageswert vorhanden => Durchschnitt = 0.0
	        $average_daily = 0.0;
	        break;
	    case 1:
            case 2:
            	//Ein (also erster Erfassungstag => meist unvollständig erfasst!) Tageswert oder
            	//zwei (also zweiter Tag, bis mind. 24:00 Uhr auch unvollständig erfasst) Tageswerte vorhanden
            	//=> einfache Durchschnittsbildung mit dem Ergebnis das Durchschnitt sicher zu niedrig
		$average_daily = array_sum($temp) / count($temp);
		break;
	    case 3:
		//Drei Tageswerte erfasst, d. h. 2. Tag wurde vollständig erfasst => Durchschnitt entspricht 2. Tageswert
		$temp = array_splice($temp, 1, 1);
		$average_daily = array_sum($temp) / count($temp);
		break;
	    default:
		//Mehrere Tageswerte erfasst, d. h. nach Entfernung der unvollständig erfassten Tageswerte
            	//für den ersten und den letzten Tag Durchschnitt über restliche Tageswerte bilden  
		$temp = array_splice($temp, 1, count($temp) - 2);
		$average_daily = array_sum($temp) / count($temp);
	}
	
	// Monatswerte berechnen, dabei nur vollständig erfasste Tage berücksichtigen
	foreach ($temp as $key => $value) {
		$month = date_parse($key)['month'];
		$year = date_parse($key)['year'];
		$temp[] = array($month . '.' . $year, $value);
		unset($temp[$key]);
	}
	$monatswerte = array();
	$monatsanzahl = array();
	foreach ($temp as $element) {
		$monatswerte[$element[0]] += $element[1];
		$monatsanzahl[$element[0]] += 1;
	}
	unset($element);
	//Hochrechnung vornehmen
	foreach ($monatswerte as $key => $value) {
		$average_temp = $value / $monatsanzahl[$key];
		$month = strstr($key, '.', true);
		$year = trim(strrchr($key, '.'), '.');
		$anzahl_tage = cal_days_in_month(CAL_GREGORIAN, $month, $year);
		$monatswerte[$key] = $average_temp * $anzahl_tage;
	}
	$average_monthly = array_sum($monatswerte) / count($monatswerte);
	if (is_nan($average_monthly))
		$average_monthly = 0.0;
	
	// Jahreswerte berechnen
	$jahreswerte = array();
	$jahresanzahl = array();
	foreach ($monatswerte as $key => $value) {
		$year = trim(strrchr($key, '.'), '.');
		$jahreswerte[$year] += $value;
		$jahresanzahl[$year] += 1;
	}
	//Hochrechnung vornehmen
	foreach ($jahreswerte as $key => $value) {
		$average_temp = $value / $jahresanzahl[$key];
		$anzahl_monate = 12;
		$jahreswerte[$key] = $average_temp * $anzahl_monate;
	}
	$average_yearly = array_sum($jahreswerte) / count($jahreswerte);
	if (is_nan($average_yearly))
		$average_yearly = 0.0;
	
	//Je nach Button Anzeige ändern
	if (isset($_POST['anzeige'])) {
		switch (strstr($_POST['anzeige'], '_', true)) {
			case "day":
				$einheit = 'KWh';
				$labels = array_keys($tageswerte);
				$values = array_map(function ($v) {
					return round($v, 2);
				}, array_values($tageswerte));
				break;
			case "month":
				$einheit = 'KWh';
				$labels = array_keys($monatswerte);
				$values = array_map(function ($v) {
					return round($v, 1);
				}, array_values($monatswerte));
				break;
			case "year":
				$einheit = 'KWh';
				$labels = array_keys($jahreswerte);
				$values = array_map(function ($v) {
					return round($v, 0);
				}, array_values($jahreswerte));
				break;
		}
	} else {
		//Zum Start die Tageswerte anzeigen
		$einheit = 'KWh';
		$labels = array_keys($tageswerte);
		$values = array_map(function ($v) {
			return round($v, 2);
		}, array_values($tageswerte));
	}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-BmbxuPwQa2lc/FVzBcNJ7UAyJxM6wuqIj61tLrc4wSX0szH/Ev+nYRRuWlolflfl" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" integrity="sha512-iBBXm8fW90+nuLcSKlbmrPcLa0OT92xO1BIsZ+ywDWZCvqsWgccV3gFoRBv0z+8dLJgyAHIhR35VZc2oM/gI1w==" crossorigin="anonymous"/>
    <title>EnergyObserver</title>
    <style type="text/css">
        table.table-bordered > thead > tr > th {
            border: 2px solid black;
        }

        table.table-bordered > tbody > tr > td {
            border: 2px solid black;
        }
    </style>
</head>
<body>
<!--suppress SqlNoDataSourceInspection -->
<div class="container-responsive m-2">
    <div class="card-group">
        <div class="card border-white">
            <div class="card-header text-center fs-2" style="background: #28a745">
                <div class="row">
                    <div class="col-1">
                    </div>
                    <div class="col-10 text-white">
                        <strong>EnergyObserver</strong>
                    </div>
                    <div class="col-1 text-right">
                        <button class="btn btn-lg btn-link text-white text-right" type="button" data-bs-toggle="modal" data-bs-target="#config"><i class="fas fa-cog fa-lg"></i></button>
                    </div>
                    <!-- Modal -->
                    <div class="modal fade fs-5" id="config" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="configLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="configLabel">Einstellungen</h5>
                                </div>
                                <div class="modal-body ">
                                    <p><span>Aktueller Strompreis: </span><span id="price"><?= $price ?></span><span> EUR/KWh</span></p>
                                    <div class="input-group fs-4">
                                        <input type="number" id="price_input" class="form-control w-75" min="0" step="0.01" placeholder="Bitte hier den neuen Strompreis in EUR/KWh eingeben" aria-label="Aktueller Strompreis" aria-describedby="button-addon2">
                                        <button type="button" id="new_price" class="btn btn-success w-25" onclick="newPrice()"><i class="fas fa-plus"> Strompreis ändern</i></button>
                                    </div>
                                    <br>
                                    <div class="input-group fs-4">
                                        <input type="text" id="add_shelly_input" class="form-control w-75" placeholder="IP-Adresse des neuen Shelly-Device" aria-label="IP-Adresse des Shelly-Device" aria-describedby="button-addon2">
                                        <button type="button" id="add_shelly" class="btn btn-success w-25" onclick="addShelly()"><i class="fas fa-plus"> Shelly hinzufügen</i></button>
                                    </div>
                                    <div id="ErrorMessage" class="form-text text-danger" hidden>Error</div>
                                    <br>
                                    <table id="shelly_table" class="table table-bordered table-hover table-sm fs-5">
										<?= array_to_table($shellys) ?>
                                    </table>
									<?php if (empty($shellys)) : ?>
                                        <div id="noShelly" class="form-text text-danger">Es
                                            wurde noch kein Shelly hinzugefügt!</div>
									<?php else : ?>
                                        <div id="noShelly" class="form-text text-danger" hidden>Es
                                            wurde noch kein Shelly hinzugefügt!</div>
									<?php endif; ?>
                                </div>
                                <div class="modal-footer">
				    <form id="CloseModal" action="report.php" method="post">
                                        <input id="CloseModal-filename" type="text" name="filename" value="" hidden>
                                        <button type="button" class="btn btn-secondary" onclick="closeModal()">Zurück</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <form action="report.php" method="post">
                <div class="row justify-content-md-center">
					<?php foreach ($shellys as $single_shelly) : ?>
                        <div class="col-sm-auto mt-3">
                            <button type="submit" class="btn btn-lg <?php if ($single_shelly['filename'] == $filename) : ?>btn-success<?php else : ?>btn-secondary<?php endif; ?> text-white" name="filename" value="<?= $single_shelly['filename'] ?>"><strong><?= $single_shelly['Name'] ?></strong></button>
                        </div>
					<?php endforeach; ?>
                </div>
                <br>

                <div class="row">

                    <div class="col-4">
                        <div class="card h-100 text-center bg-light">
                            <div class="card-body">
                                <h5 class="card-title">&empty; täglicher Stromverbrauch</h5>
                                <h5 class="card-text"><?= round($average_daily, 2) ?> KW</h5>
                                <h5 class="card-text" id="average_daily"><?= round($average_daily * $price, 2) ?> EUR</h5>
                                <!--<p class="small">Es werden nur vollständig erfasste Tage berücksichtigt!</p>-->
                            </div>
                            <div class="card-footer bg-transparent">
                                <button type="submit" class="btn btn-success" name="anzeige" value="day_<?= $filename ?>">Tage anzeigen</button>
                            </div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="card h-100 text-center bg-light">
                            <div class="card-body">
                                <h5 class="card-title">&empty; monatlicher Stromverbrauch</h5>
                                <h5 class="card-text"><?= round($average_monthly, 1) ?> KWh</h5>
                                <h5 class="card-text" id="average_monthly"><?= round($average_monthly * $price, 2) ?> EUR</h5>
                            </div>
                            <div class="card-footer bg-transparent">
                                <button type="submit" class="btn btn-success" name="anzeige" value="month_<?= $filename ?>">Monate anzeigen</button>
                            </div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="card h-100 text-center bg-light">
                            <div class="card-body">
                                <h5 class="card-title">&empty; jährlicher Stromverbrauch</h5>
                                <h5 class="card-text"><?= round($average_yearly, 0) ?> KWh</h5>
                                <h5 class="card-text" id="average_yearly"><?= round($average_yearly * $price, 2) ?> EUR</h5>
                            </div>
                            <div class="card-footer bg-transparent">
                                <button type="submit" class="btn btn-success" name="anzeige" value="year_<?= $filename ?>">Jahre anzeigen</button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <br>
    <canvas id="myChart"></canvas>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta2/dist/js/bootstrap.bundle.min.js" integrity="sha384-b5kHyXgcpbZJO/tY9Ul7kGkf1S0CWuKcCD38l8YkeH8z8QjE0GmW1gYU5S9FOnJ0" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@2.9.4/dist/Chart.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/table-to-json@1.0.0/lib/jquery.tabletojson.min.js" integrity="sha256-H8xrCe0tZFi/C2CgxkmiGksqVaxhW0PFcUKZJZo1yNU=" crossorigin="anonymous"></script>
<script>
    function closeModal() {
        list = document.getElementsByClassName("btn-success");
        filename = list[2].value;
        document.getElementById("CloseModal-filename").value = filename;
        document.getElementById("CloseModal").submit();
    }

    function save_config() {
        var table = $('#shelly_table').tableToJSON({
            ignoreColumns: [3]
        });
        let data = new FormData();
        data.append("price", document.getElementById('price').innerText)
        data.append("config", JSON.stringify(table));
        let request = new XMLHttpRequest();
        request.open('POST', 'report.php');
        request.send(data);
    }

    function deleteRow(btn) {
        debugger
        var row = btn.parentNode.parentNode;
        let data = new FormData();
        data.append("delete", row.innerText)
        let request = new XMLHttpRequest();
        request.open('POST', 'report.php');
        request.send(data)
        //Delete table-row
        row.parentNode.removeChild(row);
        var x = document.getElementById("shelly_table").rows.length;
        if (x == 1)
            document.getElementById('noShelly').removeAttribute('hidden');
        save_config();
    }

    function addShelly() {
        var ip = document.getElementById("add_shelly_input").value;

        //Prüfen ob IP eine IP-Adresse darstellt
        if (!ValidateIPaddress(ip)) {
            //Es wurde keine gültige IP-Adresse eingeben
            document.getElementById('ErrorMessage').innerHTML = 'Ihre Eingabe stellt keine gültige IP-Adresse dar!<br>Bitte überprüfen Sie Ihre Eingabe!';
            document.getElementById('ErrorMessage').removeAttribute('hidden');
            return;
        } else
            document.getElementById('ErrorMessage').hidden = true;
        
        var table = document.getElementById("insert_point");

        //Prüfen ob es den Shelly gibt
        $.post("report.php", {
            "IP": ip,
        }, function (data, status) {
            if (!data) {
                //Es wurde kein Shelly unter der Adresse gefunden
                document.getElementById('ErrorMessage').innerHTML = 'Unter dieser IP wurde kein Shelly gefunden!<br>Bitte überprüfen Sie die eingegebene IP-Adresse!';
                document.getElementById('ErrorMessage').removeAttribute('hidden');
            } else {
                var i;
                for (i = 0; i < data.length; i++) {
                    // Create an empty <tr> element and add it to the last position of the table:
                    var row = table.insertRow(-1);

                    // Insert new cells (<td> elements) at the 1st and 2nd position of the "new" <tr> element:
                    var cell1 = row.insertCell(0);
                    var cell2 = row.insertCell(1);
                    var cell3 = row.insertCell(2);
                    var cell4 = row.insertCell(3);

                    cell1.innerHTML = '<a href="http://' + ip + ' " target="_blank" >' + ip + '</a>';
                    cell2.innerHTML = data[i]['Shelly-Typ'];
                    cell3.innerHTML = data[i]['Name'];
                    cell4.innerHTML = '<button class="btn btn-lg" type="button" id ="delete_row" onclick = "deleteRow(this)"><i class="fas fa-trash"></i></button>';
                }
                document.getElementById("add_shelly_input").value = "";
                document.getElementById('noShelly').hidden = true;
                save_config();
            }
        }, "json")
    }

    function newPrice() {
        var price = parseFloat(document.getElementById("price_input").value)
        if (price) {
            document.getElementById('price').innerText = price;
            document.getElementById("price_input").value = "";
            save_config();
        }
    }

    function ValidateIPaddress(ipaddress) {
        var ipformat = /^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
        if (ipaddress.match(ipformat)) {
            return true;
        } else {
            return false;
        }
    }
</script>
<!-- Diagramm anzeigen -->
<script>
    let einheit = <?= json_encode($einheit); ?>;
    let labels = <?= json_encode($labels); ?>;
    let values = <?= json_encode($values); ?>;
    var ctx = document.getElementById('myChart').getContext('2d');
    var chart = new Chart(ctx, {
        // The type of chart we want to create
        type: 'bar',

        // The data for our dataset
        data: {
            labels: labels,
            datasets: [{
                label: einheit,
                backgroundColor: '#28a745',
                borderColor: '#28a745',
                data: values,
                pointRadius: 0
            }]
        },

        // Configuration options go here
        options: {
            responsive: true,
            scales: {
                yAxes: [{
                    ticks: {
                        beginAtZero: true
                    }
                }]
            },
            legend: {
                display: false
            }
        }
    })
</script>
</body>
</html>
<?php
	function array_to_table($a)
	{
		if (!empty($a)) {
			foreach ($a as $key => $item) {
				$item['IP'] = '<a href="http://' . $item['IP'] .' " target="_blank" >' . $item['IP'] . '</a>';
				unset($item['filename']);
				$a[$key] = $item;
			}
			$t = '<thead class="thead-dark">';
			$t .= '<tr><th>' . implode('</th><th>', array_keys($a[0])) . '</th><th></th></tr>';
			$t .= '</thead>';
			$t .= '<tbody id="insert_point">';
			foreach ($a as $row) {
				$t .= '<tr><td>' . implode('</td><td>', $row) . '</td><td><button class="btn btn-lg" type="button" id ="delete_row" onclick = "deleteRow(this)"><i class="fas fa-trash"></i></button></td></tr>';
			}
			$t .= '</tbody>';
			return $t;
		} else {
			$t = '<thead class="thead-dark"><tr><th>IP</th><th>Shelly-Typ</th><th>Name</th><th></th></tr></thead><tbody id="insert_point"></tbody>';
			return $t;
		}
	}
	
	function query($abfrage)
	{
		// create & initialize a curl session
		$curl = curl_init();
		
		// set our url with curl_setopt()
		curl_setopt($curl, CURLOPT_URL, $abfrage);
		
		// return the transfer as a string, also with setopt()
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		
		//Timeouts setzen
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 0);
		curl_setopt($curl, CURLOPT_TIMEOUT, 2); //timeout in seconds
		
		// curl_exec() executes the started curl session
		// $output contains the output string
		$output = curl_exec($curl);
		
		// close curl resource to free up system resources
		// (deletes the variable made by curl_init)
		curl_close($curl);
		
		$values = json_decode($output, true);
		
		return $values;
	}
?>

