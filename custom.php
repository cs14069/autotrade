<?php
class Custom {
  public static $bsti = array();
  public static $ssti = array();
  public static $trust = array();
  public static $stiIndex = 0;
  public static $trustAve = 0;
  public static $oldFloor = array();

  const stiMax = 5;
  static function chance() {
    if(count(Account::$orderList) >= Setting::maxOrder) {
      return array(Status::wait);
    }

    // sti指標を使ったチャンスを見つける
    $ret = self::stiChance();
    if($ret[0] !== Status::wait) {
      return $ret;
    }
    // boardを見てチャンスを見つける
    $ret = self::boardChance();
    if($ret[0] !== Status::wait) {
      return $ret;
    }
    return array(Status::wait);
  }
  static function danger() {
    // プログラムの損失が許容範囲を超えたら強制終了
    $assets = Account::getTotalAssets();
    if($assets < Account::$firstAssets - Setting::maxLoss) {
      return array(Status::abort);
    }

    $sellPrice = Board::$ceilPrice - Setting::tradeMargin;
    if($sellPrice < 0) {
      $sellPrice = Board::getBestAsk() + Setting::normalIncome;
    }
    $trigger = Board::$floorPrice + Setting::tradeMargin;
    if($trigger < 5) {
      $trigger = Board::getBestBid() - Setting::maxTradeLoss;
    }
    if(abs($sellPrice - $trigger) < Setting::minSpread) {
      return array(Status::wait);
    }

    // Availableが残っていれば、OCOでHOLD
    $size = Account::$available[CurrencyCode::btc];
    if($size > 0.01) {
      return array(
        Status::hold,
        Order::orderMethod => OrderMethod::oco,
        Order::size        => $size,
        Order::sellPrice   => $sellPrice,
        Order::trigger     => $trigger
        );
    }
    // 買い注文後、値段が跳ね上がり買われなかったとき放置しない
    //　床があったから買ったのに、突然床が消えた
    return array(Status::wait);
  }

  static function stiChance() {
    $data = date('Y/m/d H:i:s')."\t".Board::getBestAsk()."\t".Board::$mid."\t".Board::getBestBid()."\t".sprintf("%011.4F", Board::$bsti)."\t".sprintf("%011.4F", Board::$ssti)."\t".Board::$trustExecution;
    file_put_contents(Setting::$stiFileName, $data."\n", FILE_APPEND);
    if(Setting::verbose) {
      self::logging($data);
      echo Setting::$message;
    }
    self::$stiIndex = ++self::$stiIndex%self::stiMax;
    self::$bsti[self::$stiIndex] = Board::$bsti;
    self::$ssti[self::$stiIndex] = Board::$ssti;
    self::$trust[self::$stiIndex] = Board::$trustExecution;
    self::$trustAve = 0;
    foreach(self::$trust as $t) {
      self::$trustAve += $t;
    }
    self::$trustAve /= self::stiMax;

    // 基礎
    // 売りも買いも多いときは、スプレッドが広がる。買い傾向なら買う。

    $orderMethod = OrderMethod::ifdoco;
    $buyPrice = Board::getBestBid() + 1;
    $sellPrice = Board::getBestAsk() - 1;
    $trigger = $buyPrice - Setting::maxTradeLoss;
    if($sellPrice - $buyPrice < Setting::minSpread) {
      $sellPrice = $buyPrice + Setting::minSpread;
    }
    $order = array(
      Status::chance, 
      Order::orderMethod => $orderMethod, 
      Order::buyPrice    => $buyPrice,
      Order::sellPrice   => $sellPrice,
      Order::trigger     => $trigger
      );

    // 500万以上のbbsi
    $ret = self::bulkyStiChance();
    if($ret[0] === Status::chance) {
      return $order;
    }

    //　100万以上の買い3連続で買い
    $ret = self::continueStiChance();
    if($ret[0] === Status::chance) {
      return $order;
    }

    // 売り買いが5以上3連続
    //（売り多→前回の買い・売りより買い多→売り多）　のとき買い
    //（売り多→売り多→買い多） のとき買い

    // 0→0→0→買いのとき買い

    // 買い→買い→買い→買い売り1以上　のとき買い

    // 10000以上の買い
    // →売りが100未満程度あるが買いも0.01以上ある
    // →買いの方が多い　　とき買い

    // 10000以上の売り
    // →売りも買いも1以上ある
    // →買いの方が多い　とき買い

    // 1000以上の買い
    // →売りの方が多い　または0 0
    // →売りも買いも5以上ある　とき買い

    // もう一つ　売り売りからの急激な買いが来たらbestBid+1で買ってbestAsk-1で売る

    return array(Status::wait);
  }
  static function boardChance() {
    $ret = self::floorChance();
    if($ret[0] === Status::chance) {
      return $ret;
    }
    $ret = self::gapBoardChance();
    if($ret[0] !== Status::chance) {
      return $ret;
    }
    return array(Status::wait);

  // 買い傾向でスプレッドが大きいとき　bestBid+1で買ってbestAsk-1で売る
  // bestBidが急激に落ちたとき買い

  }
  //　大きな買いが続いたとき
  static function continueStiChance() {
    $i = (self::$stiIndex+self::stiMax-2)%self::stiMax;
    if(self::$bsti[$i] > Setting::$continueChanceIndex) {
      if(Setting::verbose) {
        self::logging('Clear1: continueStiChance()');
        echo Setting::$message;
      }
      if(self::$bsti[($i+1)%self::stiMax] > Setting::$continueChanceIndex) {
        if(Setting::verbose) {
          self::logging('Clear2: continueStiChance()');
          echo Setting::$message;
        }
        if(self::$bsti[($i+2)%self::stiMax] > Setting::$continueChanceIndex) {
          if(Setting::verbose) {
            self::logging('Accomplish: continueStiChance()');
            echo Setting::$message;
          }
          return array(Status::chance);
        }
      }
    }
    return array(Status::wait);
  }
  // 爆買いあったら買い
  static function bulkyStiChance() {
    if(self::$bsti[self::$stiIndex] > Setting::$bulkyChanceIndex) {
      if(Setting::verbose) {
        self::logging('Accomplish: bulkyStiChance()');
        echo Setting::$message;
      }
      return array(Status::chance);
    }
    return array(Status::wait);
  }
  // 天井が無くて床があったら買い
  static function floorChance() {
    if(Board::$ceilPrice > 0) {
      return array(Status::wait);
    }
    $buyPrice = Board::getBestBid() + Setting::tradeMargin;
    $sellPrice = Board::getBestAsk() - Setting::tradeMargin;
    $trigger = Board::$floorPrice + Setting::tradeMargin;
    if($trigger < Setting::tradeMargin || in_array($trigger, self::$oldFloor)) {
      foreach(self::$oldFloor as $i => $floor){
        if(abs($buyPrice - $floor) > 200) {
          unset(self::$oldFloor[$i]);
        }
      }

      return array(Status::wait);      
    }
    self::$oldFloor[] = $trigger;
    if($buyPrice - $trigger > 200) {
      return array(Status::wait);
    }else if($buyPrice - $trigger > 10) {
      $buyPrice = $trigger + 10;
    }
    if($sellPrice - $buyPrice < Setting::minSpread) {
      $sellPrice = $buyPrice + Setting::minSpread;
    }
    if(Setting::verbose) {
      self::logging('floorChance(): [Floor : '.$trigger.'] [buyPrice : '.$buyPrice.']');
      echo Setting::$message;
    }

    if(Setting::verbose) {
      self::logging('Accomplish: floorChance()');
      echo Setting::$message;
    }

    $orderMethod = OrderMethod::ifdoco;
    return array(Status::chance, 
      Order::orderMethod => $orderMethod, 
      Order::buyPrice    => $buyPrice,
      Order::sellPrice   => $sellPrice,
      Order::trigger     => $trigger
      );
  }
  // 売り買い激しくてBid Ask内で隙間があったらそこを狙う
  static function gapBoardChance() {
    $a = ''; $b = '';
    for($i = 0; $i < Setting::boardLength; $i++) {
      $a .= Board::$ask[$i]->price."\t".Board::$ask[$i]->size."\t";
      $b .= Board::$bid[$i]->price."\t".Board::$bid[$i]->size."\t";
    }
    $data = $a.$b.Board::$mid."\n";
    file_put_contents(Setting::$boardFileName, $data, FILE_APPEND);

    $sellPrice = -1;
    $buyPrice = -1;
    $gapAsk = array();
    $gapBid = array();
    $sellPrice = Board::$mid - Setting::tradeMargin;
    if(Board::$ceilPrice > 0 && $sellPrice >= Board::$ceilPrice) {
      $sellPrice = Board::$ceilPrice - Setting::tradeMargin;
    }

    for($i = 0, $max = Setting::boardLength-1; $i < $max; $i++) {
      // if(Board::$ask[$i+1]->price - Board::$ask[$i]->price > Setting::gapBoard ) {
      //   $sellPrice = Board::$ask[$i]->price - Setting::tradeMargin; 
      // }
    }
    for($i = Setting::boardLength-1; $i > 0; $i--) {
      if(Board::$bid[$i-1]->price - Board::$bid[$i]->price > Setting::gapBoard ) {
        $buyPrice = Board::$bid[$i]->price + Setting::tradeMargin;
      }
    }

    if($sellPrice > 0 && $buyPrice > 0 && $sellPrice > $buyPrice + Setting::minSpread ) {
      $orderMethod = OrderMethod::ifdoco;
      $trigger = Board::$floorPrice - 1;
      if($trigger < 0 || $trigger >= $buyPrice) {
        $trigger = $buyPrice - Setting::maxTradeLoss;
      }
      return array(Status::chance, 
        Order::orderMethod => $orderMethod, 
        Order::buyPrice    => $buyPrice,
        Order::sellPrice   => $sellPrice,
        Order::trigger     => $trigger
        );
    }
    return array(Status::wait);
  }

  static function logOrder($id, $order) {
    $content = date('Y/m/d H:i:s')."\n";
    $content .= json_encode($order, JSON_PRETTY_PRINT);
    file_put_contents(Setting::$orderFileName, $content);
  }

  static function logging($message, $point = '') {
    $message .= "\n";
    if(!empty($point)) {
      $message = "------ $point -------\n".$message."---- $point end -----\n";
    }
    file_put_contents(Setting::$logFileName, $message, FILE_APPEND);
    Setting::$message = $message;
  }
}