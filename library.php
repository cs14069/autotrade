<?php
/*
 * This code is a library of bitflyerapi
 * Called by autotrade.php
 * author EOM-MOE
 */

class Setting {
  const version = 1.0;
  const debug = false;
  const verbose = true;
  // defaultAmount < amount <= defaultAmount+rand(0, fraction)/100 <= maxEachAmount
  // Default Trade Amount
  const defaultAmount = 0.2;
  // Max each trade amount
  const maxEachAmount = 0.25;
  // Max free amount
  const maxFreeAmount = 1.5;
  // Max fraction
  const fraction = 50;
  // Min Spread < (SellPrice - BuyPrice)
  const minSpread = 25;
  // Max loss by this program
  const maxLoss = 750;
  // MAX loss at each trade
  const maxTradeLoss = 50;
  // Normal income line
  const normalIncome = 30;
  // Trade margin
  const tradeMargin = 5;
  // AutoTrade::main()
  const interval = 4000000;
  // Custom::chance()
  const maxOrder = 3;
  const stiFileNameFormat = '%dirPath%/data/%mode%/sti.%startTime%.csv';
  public static $stiFileName = '';
  public static $bulkyChanceIndex = 5000000;
  // Custom::continueStiChance()
  public static $continueChanceIndex = 1000000;
  // Custom::gapBoardChance()
  const gapBoard = 30;
  const boardFileNameFormat = '%dirPath%/data/%mode%/askbidmid.%startTime%.csv';
  public static $boardFileName = '';
  // Custom::addOrder
  const orderFileNameFormat = '%dirPath%/log/%mode%/order.%startTime%.txt';
  public static $orderFileName = '';
  // Access::checkAllExecution()
  const executionCount = 50;
  // Board::updateBoard()
  const boardLength = 20;
  const floorAmount = 15;
  const ceilAmount = 15;
  const bannerIndex = 50;
  // Board::updateExecution()
  const trustSecond = 10;
  const trustWallPosition = 9;
  // Custom::logging()
  const logFileNameFormat = '%dirPath%/log/%mode%/log.%startTime%.debug_%debug%.txt';
  public static $logFileName = '';
  public static $message = '';
  static function setFileName() {
    self::$boardFileName = self::formatFileName(self::boardFileNameFormat);
    self::$stiFileName = self::formatFileName(self::stiFileNameFormat);
    self::$logFileName = self::formatFileName(self::logFileNameFormat);
    self::$orderFileName = self::formatFileName(self::orderFileNameFormat);
  }
  static function formatFileName($format) {
    return str_replace('%debug%', self::debug?'true':'false', str_replace('%mode%', Status::$mode, str_replace('%startTime%', Status::$startTime, str_replace('%dirPath%', dirname(__FILE__), $format))));
  }
  static function getBuyAmount($price) {
    $amount  = self::maxFreeAmount;
    if(self::defaultAmount >= self::maxFreeAmount) {
      Custom::logging('Abort: getAmount()');
      echo Setting::$message;
      die();
    }
    if(!Account::canBuy($price, self::defaultAmount)) {
      return -1;
    }
    while($amount >= self::maxEachAmount || !Account::canBuy($price, $amount)) {
      $amount  = self::defaultAmount; 
      $amount += mt_rand(1, self::fraction)/1000;
    }
    return $amount;
  }
  static function getSellAmount($buyAmount) {
    $amount  = self::maxFreeAmount;
    if(self::defaultAmount >= self::maxEachAmount) {
      Custom::logging('Abort: getAmount()');
      echo Setting::$message;
      die();
    }
    if(!Account::canSell($buyAmount - self::defaultAmount)) {
      return -1;
    }
    while($amount >= self::maxEachAmount || !Account::canSell($buyAmount-$amount)) {
      $amount  = self::defaultAmount; 
      $amount -= mt_rand(1, self::fraction)/1000;
    }
    return $amount;
  }
}

class Account {
	const endpoint = 'https://api.bitflyer.jp';
	const apiKey = '';
	const apiSecret = '';
	public static $balance = array(
    CurrencyCode::jpy => 0,
    CurrencyCode::btc => 0,
    );
  public static $available = array(
    CurrencyCode::jpy => 0,
    CurrencyCode::btc => 0,    
    );
  public static $firstAssets = 0;
  public static $firstBtc = 0;

  public static $orderList = array();
  public static $orderDoneList = array();
  public static $executionList = array();
  public static $executionChecked = 0;
  private static $executionIndex = 0;
  static function addOrder($id, $o) {
    self::$orderList[$id] = $o;
  }
  static function cancelOrder($id) {
    unset(self::$orderList[$id]);
  }
  static function deleteOrder() {
    // echo "------- before ------\n";
    // echo "------- self::orderList ------\n";
    // var_dump(self::$orderList);
    // echo "------- self::orderDoneList ------\n";
    // var_dump(self::$orderDoneList);
    // echo "------- before end ------\n";
    for($i = self::$executionIndex, $max = count(self::$executionList); $i < $max; $i++) {
      echo self::$executionList[$i]->id."\t";
      echo self::$executionList[$i]->parent_order_state."\n";
      if(self::$executionList[$i]->parent_order_state === OrderState::active) {
        echo "active\n";
        unset(self::$executionList[$i]);
        continue;
      }
      if(isset(self::$orderList[self::$executionList[$i]->parent_order_acceptance_id])) {
        if(self::$executionList[$i]->parent_order_state === OrderState::completed) {         
                echo '333333333333333'."\n";

          self::$orderDoneList[self::$executionList[$i]->parent_order_acceptance_id] = self::$executionList[$i];
        }
        unset(self::$orderList[self::$executionList[$i]->parent_order_acceptance_id]);
        echo "\n\t\t\tUNSET\t\t\t\n";
      }
    }
    echo '22222222222222222222222'."\n";
    echo 'count order '.count(self::$orderList)."\t";
    echo 'executionIndex '.self::$executionIndex."\t";
    echo 'count executionList' .count(self::$executionList)."\n";
    // echo "------- after ------\n";
    // echo "------- self::orderList ------\n";
    // var_dump(self::$orderList);
    // echo "------- self::orderDoneList ------\n";
    // var_dump(self::$orderDoneList);
    // echo "------- after end ------\n";
  }
  static function setBalance($jpy, $btc) {
    self::$balance[CurrencyCode::jpy] = $jpy;
    self::$balance[CurrencyCode::btc] = $btc;
  }  
  static function setAvailable($jpy, $btc) {
    self::$available[CurrencyCode::jpy] = $jpy;
    self::$available[CurrencyCode::btc] = $btc - self::$firstBtc;
  }
  static function addExecutionList($json) {
    if(empty($json)) { return ; }
    self::$executionList += array_reverse($json);
  }
  static function canBuy($price, $amount) {
    if($amount >= Setting::maxFreeAmount || $price*$amount > self::$available[CurrencyCode::jpy]) {
      if(Setting::verbose) {
        Custom::logging('Cannot Buy: [price : '.$price.'] [amount : '.$amount.']');
        echo Setting::$message;
      }
      return false;
    }
    return true;
  }
  static function canSell($amount) {
    // if($amount >= Setting::maxFreeAmount || $amount > self::$available[CurrencyCode::btc]) {
    //   if(Setting::verbose) {
    //     Custom::logging('Cannot Sell: [amount : '.$amount.']');
    //     echo Setting::$message;
    //   }
    //   return false;
    // }
    return true;
  }
  static function getTotalAssets() {
    return self::$balance[CurrencyCode::jpy]+self::$balance[CurrencyCode::btc]*Board::getBestBid();
  }
}

class Board {
  public static $bid = array();
  public static $ask = array();
  public static $mid = -1;
  public static $oldMid = -1;
  public static $volumeBuy = 0;
  public static $volumeSell = 0;
  public static $trustExecution = 0;
  public static $bsti = 0;
  public static $ssti = 0;
  public static $floorPrice = -1;
  public static $ceilPrice = -1;
  static function updateBoard($json) {
    if(empty($json)) { return ; }
    self::$bid = array_slice($json->bids, 0, Setting::boardLength);
    self::$ask = array_slice($json->asks, 0, Setting::boardLength);
    self::$mid = $json->mid_price;
    $floorAmount = 0;
    $ceilAmount = 0;
    $trust = min(array(Setting::trustWallPosition, Setting::boardLength));
    for($i = 0; $i < $trust; $i++) {
      if(self::$bid[$i]->size > Setting::floorAmount && self::$bid[$i]->size > $floorAmount) {
        $floorAmount = self::$bid[$i]->size;
        self::$floorPrice = self::$bid[$i]->price;
      }
      if(self::$ask[$i]->size > Setting::ceilAmount && self::$ask[$i]->size > $ceilAmount) {
        $ceilAmount = self::$ask[$i]->size;
        self::$ceilPrice = self::$ask[$i]->price;
      }
    }
    if(self::$mid > self::$oldMid + Setting::bannerIndex) {
      system('banner +'.self::$mid.'+');
      self::$oldMid = self::$mid;
    }else if(self::$mid < self::$oldMid - Setting::bannerIndex) {
      system('banner -'.self::$mid.'-');
      self::$oldMid = self::$mid;
    }
    // echo "\n------------  debug [array] updateBoard  --------------\n";
    // var_dump(self::$bid);
    // var_dump(self::$ask);
    // var_dump(self::$mid);
    // echo "----------  debug updateBoard end  --------------\n\n";
  }
  static function getBestBid() {
    return self::$bid[0]->price;
  }
  static function getBestAsk() {
    return self::$ask[0]->price;
  }
  static function updateExecution($json) {
    self::$volumeBuy = 0;
    self::$volumeSell = 0;
    self::$trustExecution = 0;
    $trustUntil = strtotime('-9 hour -'.Setting::trustSecond.' second');
    foreach($json as $exe) {
      if(strtotime($exe->exec_date) - $trustUntil < 0) {
        // echo "exec_date  : ".date('Y/m/d H:i:s', strtotime($exe->exec_date))."\n";
        // echo "trustUntiil: ".date('Y/m/d H:i:s', strtotime('-9 hour -'.Setting::trustSecond.' second'))."\n";
        break;
      }
      if($exe->side === Side::buy) {
        self::$volumeBuy += $exe->price * $exe->size;
      } else {
        self::$volumeSell += $exe->price * $exe->size;
      }
      self::$trustExecution++;
    }
    if(self::$volumeBuy === 0) {
      self::$volumeBuy = 1;
    }
    if(self::$volumeSell === 0) {
      self::$volumeSell = 1;
    }
    self::$ssti = self::$volumeSell / self::$volumeBuy * self::$trustExecution;
    self::$bsti = self::$volumeBuy / self::$volumeSell * self::$trustExecution;
    if(Setting::verbose) {
      Custom::logging('Last '.Setting::trustSecond.' seconds Volume: [BUY : '. self::$volumeBuy.'] [SELL : '.self::$volumeSell.']');
      echo Setting::$message;
      Custom::logging('ShortTrendIndex: [TrustCount : '.self::$trustExecution.'] [bsti : '.self::$bsti.'] [ssti : '.self::$ssti.']');
      echo Setting::$message;
    }
  }
}


class Order {
  const orderMethod = 'orderMethod';
  const conditionType = 'conditionType';
  const side = 'side';
  const price = 'price';
  const size = 'size';
  const trigger = 'trigger';
  const offset = 'offset';
  const buyPrice = 'buyPrice';
  const sellPrice = 'sellPrice';
  const expire = 'expire';

  public $productCode = ProductCode::btc;
  public $orderType = OrderType::limit;
  public $side = Side::buy;
  public $price = -1;
  public $size = 0;
  public $expire = Expire::year;
  public $timeInForce = TimeInForce::gtc;
  public $param = array();
  public $method = OrderMethod::ifdoco;

  public $sellLine = array();

  function setProductCode($pc) {
    $this->productCode = $pc;
  }
  function setOrderType($ot) {
    $this->orderType = $ot;
  }
  function setSide($s) {
    $this->side = $s;
  }
  function setPrice($p) {
    $this->price = $p;
  }
  function setSize($s) {
    $this->size = $s;
  }
  function setExpire($e) {
    $this->expire = $e;
  }
  function setTimeInForce($tif) {
    $this->timeInForce = $tif;
  }
  function setParam($p) {
    $this->param = $p;
  }
  function paramToString() {
    $ret = '';
    foreach($this->param as $a) {
      foreach($a as $k => $v) {
        $ret .= "[$k : $v] ";
      }
    }
    return $ret;
  }
  function setOrderMethod($m) {
    $this->method = $m;
  }
}

class Status {
  const danger = 'DANGER';
  const abort = 'ABORT';
  const fail = 'FAIL';
  const wait = 'WAIT';
  const success = 'SUCCESS';
  const chance = 'CHANCE';
  const hold = 'HOLD';
  public static $mode = '';
  public static $startTime = 0;
  public static $accessCounter = 0;
  private static $lastShowCountTime = 0;
  static function accessCount() {
    self::$accessCounter++;
    $time = time();
    if(self::$lastShowCountTime <= $time - 60) {
      self::$lastShowCountTime = $time;
      if(self::$accessCounter > 200) {
        Custom::logging('Too much access via API.');
        return Status::abort;
      }
      if(Setting::verbose) {
        Custom::logging('Access count in a minute: '.self::$accessCounter);
        echo Setting::$message;
      }
      self::$accessCounter = 0;
    }
    return Status::success;
  }
  static function fatalError($ret) {
    if($ret === self::fail || $ret === self::abort) {
      Custom::logging('FatalError: '.date('Y/m/d H:i:s'));
      echo Setting::$message;
      return true;
    }
    return false;
  }

}

class CurrencyCode {
  const btc = 'BTC';
  const jpy = 'JPY';
}
class ProductCode {
	const btc = 'BTC_JPY';
	const btcfx = 'FX_BTC_JPY';
	const eth = 'ETH_BTC';
}
class OrderType {
	const limit = 'LIMIT';
	const market = 'MARKET';
}
class ConditionType {
  const limit = 'LIMIT';
  const market = 'MARKET';
  const stop = 'STOP';
  const stopLimit = 'STOP_LIMIT';
  const trail = 'TRAIL';
}
class OrderMethod {
  const simple = 'SIMPLE';
  const ifd = 'IFD';
  const oco = 'OCO';
  const ifdoco = 'IFDOCO';
}
class Side {
	const buy = 'BUY';
	const sell = 'SELL';
}
class TimeInForce {
	const gtc = 'GTC';
	const ioc = 'IOC';
	const fok = 'FOK';	
}
class Expire {
  const one = 1;
  const two = 2;
  const five = 5;
  const quarter = 15;
  const halfhour = 30;
  const hour = 60;
  const day = 1440;
  const week = 10080;
  const month = 43200;
  const year = 525600;
}
class Health {
  const normal = 'NORMAL';
  const busy = 'BUSY';
  const veryBusy = 'veryBusy';
  const stop = 'STOP';
}
class OrderState {
  const active = 'ACTIVE';
  const completed = 'COMPLETED';
  const canceled = 'CANCELED';
  const expired = 'EXPIRED';
  const rejected = 'REJECTED';
}