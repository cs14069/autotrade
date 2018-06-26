<?php
/*
* API https://www.btcc.com/apidocs/spot-exchange-market-data-rest-api
*/
define('ENDPOINT', 'https://data.btcchina.com/data/historydata?limit=50&sincetype=time&since=');
define('SLEEP', 5);
define('VERSION', 0.3);

$last = time();
echo "Access to:".ENDPOINT.$last."\n";
echo "Sleep :".SLEEP."sec\n";
$count = 0;

$pfirst = 0;
$plast = 0;
$pmax = 0;
$pmin = 0;
$paverage = 0;
$access = 0;
while(true) {
	if(!$access%=20) {
		echo " first  \t  max   \t  min   \t  last  \taverage \n";
	}
	$access++;
	$json = getHistoryDataJson($last);
	for($i = 0, $count = count($json); $i < $count; $i++) {
		$price[] = $json[$i]->price;
	}
	if($count !== 0) {
		$last = $json[$count-1]->date;
		$pfirst = addUpDownMark($pfirst, $json[0]->price);
		$pmax = addUpDownMark(intval($pmax), max($price));
		$pmin = addUpDownMark(intval($pmin), min($price));
		$plast = addUpDownMark($plast, $json[$count-1]->price);
		$paverage = addUpDownMark($paverage, array_sum($price)/$count);
		echo "$pfirst\t$pmax\t$pmin\t$plast\t$paverage\n";
	}
	unset($price);
	sleep(SLEEP);
}

function getHistoryDataJson($last) {
	try{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, ENDPOINT);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Btceom/'.VERSION.' Contact me at eom.moe@r9r.info');
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		$result = curl_exec($ch);
	} catch(Exception $e) {
		echo "Error: ".$e."\n";
	}
	return json_decode($result);
}

function addUpDownMark($old, $new) {
	return $old < $new
	? "".sprintf('%6.2F', $new)."△"
	: "\033[0;31m".sprintf('%6.2F', $new)."▲\033[0m";
}

