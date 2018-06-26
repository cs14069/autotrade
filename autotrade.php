<?php 
include dirname(__FILE__).'/library.php';
include dirname(__FILE__).'/custom.php';

class AutoTrade {
  function init($mode) {
    Status::$mode = $mode;
    Status::$startTime = date('YmdHis');
    Setting::setFileName();
    Custom::logging('AutoTrade by eom.moe starts.');
    echo Setting::$message;
    Custom::logging('Mode: '.$mode);
    echo Setting::$message;
    $sec = Setting::interval/1000000;
    Custom::logging('Interval: '.$sec.'[sec]');
    echo Setting::$message;
    Custom::logging('Version: '.Setting::version);
    echo Setting::$message;
    Custom::logging('DefaultAmount: '.Setting::defaultAmount);
    echo Setting::$message;
    $retval = Access::checkBalance();
    if(Status::fatalError($retval)) { return $retval; }
    $retval = Access::checkTicker();
    if(Status::fatalError($retval)) { return $retval; }
    Account::$firstAssets = Account::getTotalAssets();
    Account::$firstBtc = Account::$available[CurrencyCode::btc];
    Account::$available[CurrencyCode::btc] = 0;
    for($i = 0; $i < Custom::stiMax; $i++) {
      Custom::$bsti[] = 0;
      Custom::$ssti[] = 0;
      Custom::$trust[] = 0;
    }
    return Status::success;
  }

  function main() {
    $start = time();
    if(Status::fatalError($this->init('main'))) {
      Custom::logging('Abort: showInfo()');
      echo Setting::$message;
      die();
    }

    $retval = Status::success;
    while($retval !== Status::abort) {
      $retval = $this->loop();
      usleep(Setting::interval);
      if($retval === Status::fail) {
        Custom::logging('Error: main()');
        echo Setting::$message;
        $this->errorCheck();
      }
    }
    $runTime = (time() - $start)/60;
    Custom::logging('Run Time: '.$runTime.'[min]');
    echo Setting::$message;
    Custom::logging('Abort: main()');
    echo Setting::$message;
    die();
  }

  function loop() {
    $retval = Access::checkAllParentOrder();
    if(Status::fatalError($retval)) { return $retval; }
    $retval = Access::checkBalance();
    if(Status::fatalError($retval)) { return $retval; }
    $retval = Access::checkAllExecution();
    if(Status::fatalError($retval)) { return $retval; }
    $retval = Access::checkBoard();
    if(Status::fatalError($retval)) { return $retval; }

    $ret = Custom::chance();
    if($ret[0] === Status::chance) {
      $orderMethod = $ret[Order::orderMethod];
      if($orderMethod === OrderMethod::ifdoco) {
        $ret = $this->ifdocoBuy($ret[Order::buyPrice], $ret[Order::sellPrice], $ret[Order::trigger]);
      }else if($orderMethod === OrderMethod::ifd) {
        $ret = $this->ifdBuy($ret[Order::buyPrice], $ret[Order::sellPrice]);
      } else {
        Custom::logging('Unimplemented chance ('.$orderMethod.'): loop()');
        echo Setting::$message;
        return Status::abort;
      }
      if(Status::fatalError($ret[0])) { return $ret[0]; }
      if($ret[0] !== Status::wait) {
        $retval = Access::newOrder($orderMethod, $ret);   
      }
    }else if($ret[0] === Status::wait) {
      if(Setting::verbose) {
        Custom::logging('Wait: chance()');
        echo Setting::$message;
      }
    } else {
      return $ret[0];
    }
    if(Status::fatalError($retval)) { return $ret[0]; }

    $ret = Custom::danger();
    if($ret[0] === Status::danger) {
      $retval = Access::cancelAllOrder();
      if(Status::fatalError($retval)) { return $retval; }
      $orderMethod = OrderMethod::simple;
      $ret = $this->marketSellAll();
      $retval = Access::newOrder($orderMethod, $ret); 
      Custom::logging('Danger Big Loss: '.Setting::maxLoss.'');
      echo Setting::$message;
      Custom::logging('First Assets: '.Account::$firstAssets);
      echo Setting::$message;
      Custom::logging('Final Assets: '.Account::getTotalAssets());
      echo Setting::$message;
      return Status::abort;
    }else if($ret[0] === Status::hold) {
      $orderMethod = $ret[Order::orderMethod];
      if($orderMethod === OrderMethod::oco) {
        $ret = $this->ocoSell($ret[Order::size], $ret[Order::sellPrice], $ret[Order::trigger]);
      } else {
        Custom::logging('Unimplemented danger ('.$orderMethod.'): loop()');
        echo Setting::$message;
        return Status::abort;
      }
      if(Status::fatalError($ret[0])) { return $ret[0]; }
      if($ret[0] !== Status::wait) {
        $retval = Access::newOrder($orderMethod, $ret);   
      }
    }else if($ret[0] === Status::wait) {
      if(Setting::verbose) {
        Custom::logging('Wait: danger()');
        echo Setting::$message;
      }
    } else {
      return $ret[0];
    }
    if(Status::fatalError($retval)) { return $ret[0]; }
    return Status::success;
  }

  function ifdBuy($buyPrice, $sellPrice) {
    $buyAmount = Setting::getBuyAmount($buyPrice);
    if($buyAmount < 0) { return array(Status::wait); }
    $sellAmount = Setting::getSellAmount($buyAmount);
    if($sellAmount < 0) { return array(Status::wait); }
    $param[] = array(
      Order::conditionType => ConditionType::limit,
      Order::side          => Side::buy,
      Order::price         => $buyPrice,
      Order::size          => $buyAmount
      );

    $param[] = array(
      Order::conditionType => ConditionType::limit,
      Order::side          => Side::sell,
      Order::price         => $sellPrice,
      Order::size          => $sellAmount
      );    
    $param[Order::expire] = Expire::two;
    return $param;
  }
  function ifdocoBuy($buyPrice, $sellPrice, $trigger) {
    $buyAmount = Setting::getBuyAmount($buyPrice);
    if($buyAmount < 0) { return array(Status::wait); }
    $sellAmount = Setting::getSellAmount($buyAmount);
    if($sellAmount < 0) { return array(Status::wait); }

    $param[] = array(
      Order::conditionType => ConditionType::limit,
      Order::side          => Side::buy,
      Order::price         => $buyPrice,
      Order::size          => $buyAmount
      );

    $param[] = array(
      Order::conditionType => ConditionType::limit,
      Order::side          => Side::sell,
      Order::price         => $sellPrice,
      Order::size          => $sellAmount
      );

    $param[] = array(
      Order::conditionType => ConditionType::stop,
      Order::side          => Side::sell,
      Order::trigger       => $trigger,
      Order::size          => $buyAmount
      );
    $param[Order::expire] = Expire::one;

    return $param;
  }
  function marketSellAll() {
    $sellAmount = Account::$available[CurrencyCode::btc];
    $param[] = array(
      Order::conditionType => ConditionType::trail,
      Order::side          => Side::sell,
      Order::size          => $sellAmount,
      Order::offset        => 1
      );
    $param[Order::expire] = Expire::one;
    return $param;
  }
  function ocoSell($size, $sellPrice, $trigger) {
    $param[] = array(
      Order::conditionType => ConditionType::limit,
      Order::side          => Side::sell,
      Order::price         => $sellPrice,
      Order::size          => $size
      );

    $param[] = array(
      Order::conditionType => ConditionType::stop,
      Order::side          => Side::sell,
      Order::trigger       => $trigger,
      Order::size          => $size
      );    
    $param[Order::expire] = Expire::five;
    return $param;
  }
  function errorCheck() {
    $maintenanceRest = abs(strtotime('04:10:00') - strtotime('now'));
    if($maintenanceRest < 60*10) {
      Custom::logging('Sleep('.$maintenanceRest.'): '.date('Y/m/d H:i:s'));
      echo Setting::$message;
      sleep($maintenanceRest);
    } else {
      do {
        sleep(30);
        $retval = Access::checkHealth();
        if($retval === Status::abort) {
          sleep(180);
          Custom::logging('Sleep(180): checkHealth()');
          $retval = Access::checkHealth();
          if($retval === Status::abort) {
            Custom::logging('Abort: checkHealth()');
            echo Setting::$message;
            die();
          }
        }
      } while($retval === Status::fail);
    }
  }
  // easy mode
  function easy() {
    if(Status::fatalError($this->init('easy'))) {
      Custom::logging('Abort: showInfo()');
      echo Setting::$message;
      die();
    }
    $end = false;
    while(true) {
      usleep(Setting::interval);
      $retval = Access::checkBoard();
      if(Status::fatalError($retval)) { continue; }
      $retval = Access::checkAllParentOrder();
      if(Status::fatalError($retval)) { continue; }
      $buyPrice = Board::getBestBid()+1;
      $sellPrice = Board::getBestAsk()-1;
      $trigger = $buyPrice - Setting::maxTradeLoss;
      if($end) {continue;}
      if($sellPrice - $buyPrice < Setting::minSpread) {
        Custom::logging('Too Small Spread.');
        echo Setting::$message;
        continue;
      } else {
        Custom::logging('Buy!');
        echo Setting::$message;        
      }
      $ret = $this->ifdocoBuy($buyPrice, $sellPrice, $trigger);
      if($ret[0] === Status::wait) {
        continue;
      }
      $end = true;
      $retval = Access::newOrder(OrderMethod::ifdoco, $ret);
      if(Status::fatalError($retval)) {
        Custom::logging('Abort: newOrder()');
        echo Setting::$message;
        die();
      }
    }
    die();
  }

  // test mode
  function test() {
    if(Status::fatalError($this->init('test'))) {
      Custom::logging('Abort: showInfo()');
      echo Setting::$message;
      die();
    }

    echo "the end\n";
    die();
    $end = false;
    while(!$end) {
      sleep(3);
      $retval = Access::checkAllParentOrder();
      if(Status::fatalError($retval)) { $end = true; continue; }
      if(count(Account::$orderList) >= Setting::maxOrder) {
        Custom::logging('Too much order');
        echo Setting::$message;
        continue;
      }
      $retval = Access::checkBoard();
      if(Status::fatalError($retval)) { 
        Custom::logging('Abort: checkBoard()');
        echo Setting::$message;
        die();
      }
      $ret = Custom::gapBoardChance();
      if($ret[0] !== Status::chance) {
        Custom::logging('Not Chance Now');
        echo Setting::$message;        
        continue;
      }
      $orderMethod = $ret[Order::orderMethod];
      if($orderMethod === OrderMethod::ifdoco) {
        $ret = $this->ifdocoBuy($ret[Order::buyPrice], $ret[Order::sellPrice], $ret[Order::trigger]);
      }else if($orderMethod === OrderMethod::ifd) {
        $ret = $this->ifdBuy($ret[Order::buyPrice], $ret[Order::sellPrice]);
      } else {
        Custom::logging('Unimplemented chance ('.$orderMethod.'): loop()');
        echo Setting::$message;
        return Status::abort;
      }
      if(Status::fatalError($ret[0])) { $end = true; continue; }
      if($ret[0] !== Status::wait) {
        $retval = Access::newOrder($orderMethod, $ret);   
        if(Status::fatalError($retval)) { $end = true; continue; }
      }
    }
    echo $retval."\n";
    die();
  }
}

class Access {
  // Order 
  static function newOrder($method, $children) {
    $param = array();
    $expire = $children[Order::expire];
    $nofChild = 0;
    try{
      if($children[0][Order::side] === Side::buy && !Account::canBuy($children[0][Order::price], $children[0][Order::size])) {
        return Status::wait;
      }else if($children[0][Order::side] === Side::sell && !Account::canSell($children[0][Order::size])) {
        return Status::wait;
      }
      foreach($children as $child) {
        if(!isset($child[Order::conditionType])) { continue; }
        $nofChild++;
        if($child[Order::conditionType] === ConditionType::limit) {
          $param[] = array(
            'product_code'   => ProductCode::btc,
            'condition_type' => $child[Order::conditionType],
            'side'           => $child[Order::side],
            'price'          => $child[Order::price],
            'size'           => $child[Order::size]
            );
        }else if($child[Order::conditionType] === ConditionType::market) {
          $param[] = array(
            'product_code'   => ProductCode::btc,
            'condition_type' => $child[Order::conditionType],
            'side'           => $child[Order::side],
            'size'           => $child[Order::size]
            );
        }else if($child[Order::conditionType] === ConditionType::stop) {
          $param[] = array(
            'product_code'   => ProductCode::btc,
            'condition_type' => $child[Order::conditionType],
            'side'           => $child[Order::side],
            'trigger_price'  => $child[Order::trigger],
            'size'           => $child[Order::size]
            );
        }else if($child[Order::conditionType] === ConditionType::stopLimit) {
          $param[] = array(
            'product_code'   => ProductCode::btc,
            'condition_type' => $child[Order::conditionType],
            'side'           => $child[Order::side],
            'price'          => $child[Order::price],
            'trigger_price'  => $child[Order::trigger],
            'size'           => $child[Order::size]
            );
        }else if($child[Order::conditionType] === ConditionType::trail) {
          $param[] = array(
            'product_code'   => ProductCode::btc,
            'condition_type' => $child[Order::conditionType],
            'side'           => $child[Order::side],
            'size'           => $child[Order::size],
            'offset'         => $child[Order::offset]
            );
        } else {
          echo "Error: Unknown condition_type at newOrder()\n";
          return Status::abort;
        }
      }
    } catch(Exception $e) {
      echo 'Error: '.$e."\n";
      return Status::abort;
    }

    switch($nofChild) {
      case 1: 
      if($method !== OrderMethod::simple) {
        return Status::abort;
      }
      break;
      case 2:
      if($method !== OrderMethod::ifd && $method !== OrderMethod::oco) {
        return Status::abort;
      }
      break;
      case 3:
      if($method !== OrderMethod::ifdoco) {
        return Status::abort;
      }
      break;
      default:
      return Status::abort;
    }
    return self::orderParent($method, $param, $expire);
  }
  static function orderParent($method, $param, $expire) {
    $order = new Order();
    $order->setOrderMethod($method);
    $order->setExpire($expire);
    $order->setTimeInForce(TimeInForce::gtc);
    $order->setParam($param);
    $ret = self::sendParentOrder($order);
    if($ret[0] === Status::success) {
      Account::addOrder($ret[1], $ret[2]);
      Custom::logOrder($ret[1], $ret[3]);
      Custom::logging("Order($method) Success: ".$order->paramToString());
      echo Setting::$message;
    } else {
      Custom::logging("Order($method) Fail: ".$order->paramToString());
      echo Setting::$message;
      return $ret[0];
    }
    $retval = self::checkBalance();
    return $retval;
  }
  static function sendParentOrder($o) {
    $method = 'POST';
    $path = '/v1/me/sendparentorder';
    $detail = array(
      'order_method'     => $o->method,
      'minute_to_expire' => $o->expire,
      'time_in_force'    => $o->timeInForce,
      'parameters'       => $o->param
      );
    $body = json_encode($detail);
    if(Setting::verbose) {
      Custom::logging(json_encode($detail, JSON_PRETTY_PRINT), 'sendParentOrder() order [json]');
      echo Setting::$message;
    }
    $ret = self::getPrivateJson($path, $method, $body);
    if($ret === false) { return array(Status::fail); }
    $json = json_decode($ret);
    if(Setting::verbose) {
      ob_start();
      var_dump($json);
      Custom::logging(ob_get_clean(), 'sendParentOrder()');
      echo Setting::$message;
    }
    if(!isset($json->parent_order_acceptance_id)) { return array(Status::fail); }
    return array(Status::success, $json->parent_order_acceptance_id, $detail, $body);
  }

  // Chancel Order
  static function cancelAllOrder() {
    foreach(Account::$orderList as $id => $order) {
      $retval = self::cancelParentOrder($id);
      if(Status::fatalError($retval)) { return Status::fail; }
    }
    return Status::success;
  }
  static function cancelParentOrder($id) {
    $method = 'POST';
    $path = '/v1/me/cancelparentorder';
    $detail = array(
      'product_code'    => CurrencyCode::btc,
      'parent_order_acceptance_id' => $id
      );
    $body = json_encode($detail);
    Custom::logging(json_encode($detail, JSON_PRETTY_PRINT), 'cancelParentOrder() order [json]');
    echo Setting::$message;
    $ret = self::getPrivateJson($path, $method, $body);
    if($ret === false) { return Status::fail; }
    Account::cancelOrder($id);
    $json = json_decode($ret);
    if(Setting::debug) {
      ob_start();
      var_dump($json);
      Custom::logging(ob_get_clean(), 'cancelParentOrder()');
      // echo Setting::$message;
    }
    return Status::success;    
  }

  // HTTP Public API
  static function checkHealth() {
    $method = 'GET';
    $path = '/v1/gethealth';
    $ret = self::getPublicJson($path, $method);
    if($ret === false) { return Status::abort; }
    $json = json_decode($ret);
    if(Setting::verbose) {
      ob_start();
      var_dump($json);
      Custom::logging(ob_get_clean(), 'checkHealth()');
    }
    if($json->status === Health::veryBusy || $json->status === Health::stop) {
      return Status::fail;
    }
    return Status::success;
  }
  static function checkTicker() {
    $method = 'GET';
    $path = '/v1/getticker?'.http_build_query(array(
      'product_code' => ProductCode::btc
      )
    );
    $ret = self::getPublicJson($path, $method);
    if($ret === false) { return Status::fail; }
    $json = json_decode($ret);
    if(Setting::debug) {
      ob_start();
      var_dump($json);
      Custom::logging(ob_get_clean(), 'checkTicker()');
      // echo Setting::$message;
    }
    Board::$mid = $json->ltp;
    Board::$ask[0] = new stdClass();
    Board::$ask[0]->price = $json->best_ask;
    Board::$bid[0] = new stdClass();
    Board::$bid[0]->price = $json->best_bid;
    return Status::success;    
  }
  static function checkBoard() {
    $method = 'GET';
    $path = '/v1/getboard?'.http_build_query(array(
      'product_code' => ProductCode::btc
      )
    );
    $ret = self::getPublicJson($path, $method);
    if($ret === false) { return Status::fail; }
    $json = json_decode($ret);
    if(Setting::debug) {
      ob_start();
      var_dump($json);
      Custom::logging(ob_get_clean(), 'checkBoard()');
      // echo Setting::$message;
    }
    Board::updateBoard($json);    
    return Status::success;
  }
  static function checkAllExecution() {
    $method = 'GET';
    $path = '/v1/executions?'.http_build_query(array(
      'product_code' => ProductCode::btc,
      'count'        => Setting::executionCount
      )
    );
    $ret = self::getPublicJson($path, $method);
    if($ret === false) { return Status::fail; }
    $json = json_decode($ret);
    if(Setting::debug) {
      ob_start();
      var_dump($json);
      Custom::logging(ob_get_clean(), 'checkAllExecution()');
      // echo Setting::$message;
    }
    Board::updateExecution($json);
    return Status::success;

  }

  // HTTP Private API
  static function checkBalance() {
    $method = 'GET';
    $path = '/v1/me/getbalance';
    $ret = self::getPrivateJson($path, $method);
    if($ret === false) { return Status::fail; }
    $json = json_decode($ret);

    Account::setBalance(
      $jpy = $json[0]->amount, 
      $btc = $json[1]->amount
      );
    Account::setAvailable(
      $jpy = $json[0]->available, 
      $btc = $json[1]->available
      );
    Custom::logging('Balance:  [JPY = '.Account::$balance[CurrencyCode::jpy].'] [BTC = '.sprintf("%.8F", Account::$balance[CurrencyCode::btc])."]\tAvailable: [JPY = ".Account::$available[CurrencyCode::jpy].'] [BTC = '.sprintf("%.8F", Account::$available[CurrencyCode::btc]).']');
    echo Setting::$message;
    return Status::success;
  }
  static function checkAllParentOrder() {
    $method = 'GET';
    $path = '/v1/me/getparentorders?'.http_build_query(array(
      'product_code' => ProductCode::btc,
      'count'        => 3
      )
    );
    $ret = self::getPrivateJson($path, $method);
    if($ret === false) { return Status::fail; }
    $json = json_decode($ret);
    Account::addExecutionList($json);
    Account::deleteOrder();
    if(Setting::debug) {
      ob_start();
      var_dump($json);
      Custom::logging(ob_get_clean(), 'checkAllParentOrder');
      // echo Setting::$message;
    }
    return Status::success;
  }
  // 未使用
  static function checkParentOrder($id) {
    $method = 'GET';
    $path = '/v1/me/getparentorder?'.http_build_query(array(
      'parent_order_acceptance_id' => $id
      )
    );
    $ret = self::getPrivateJson($path, $method);
    if($ret === false) { return Status::fail; }
    $json = json_decode($ret);
    if(Setting::debug) {
      ob_start();
      var_dump($json);
      Custom::logging(ob_get_clean(), 'checkParentOrder('.$id.')');
      echo Setting::$message;
    }
    return Status::success;
  }


    // CURL Access
  static function getPrivateJson($path, $method, $body = '') {
    $ret = Status::accessCount();
    if(Status::fatalError($ret)) {
      return false;
    }
    $timestamp = time();
    $text = $timestamp.$method.$path.$body;
    $text = "1493715379GET/v1/me/getbalance";
    $sign = hash_hmac('sha256', $text, Account::apiSecret);
    echo $sign."\n";
    $header = array(
      'ACCESS-KEY: '       .Account::apiKey,
      'ACCESS-TIMESTAMP: ' .$timestamp,
      'ACCESS-SIGN: '      .$sign,
      'Content-Type: '     .'application/json'
      );
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, Account::endpoint.$path);
    curl_setopt($curl, CURLOPT_HEADER, false); 
    curl_setopt($curl, CURLOPT_TIMEOUT, 10); 
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    if($body !== '') {
      curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $ret = curl_exec($curl);
    curl_close($curl);
    echo "text: ".$text."\n";
    var_dump($header);
    if(Setting::debug) {
      ob_start();
      var_dump($ret);
      Custom::logging(ob_get_clean(), 'getPrivateJson()');
      // echo Setting::$message;
    }
    return $ret;
  }
  static function getPublicJson($path, $method = 'GET', $body = '') {
    $ret = Status::accessCount();
    if(Status::fatalError($ret)) {
      return false;
    }
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, Account::endpoint.$path);
    curl_setopt($curl, CURLOPT_HEADER, false); 
    curl_setopt($curl, CURLOPT_TIMEOUT, 10); 
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $ret = curl_exec($curl);
    curl_close($curl);
    if(Setting::debug) {
      ob_start();
      var_dump($ret);
      Custom::logging(ob_get_clean(), 'getPublicJson()');
      // echo Setting::$message;
    }
    return $ret;
  }
}

(new AutoTrade())->test();

// (new AutoTrade())->easy();

// (new AutoTrade())->main();

echo "No Mode Selected\n";
