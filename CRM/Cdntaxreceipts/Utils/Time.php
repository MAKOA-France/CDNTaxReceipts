<?php

/**
 * This sort of provides the same kind of service as CRM_Utils_Time with mode
 * frozen, except that service doesn't work in mink tests because each page
 * load resets whatever you set in your test and you don't have an appropriate
 * entry point to set it unless you do it globally in hook_civicrm_config,
 * but then you have the reverse problem where you can't ever turn it off in
 * a given test.
 */
class CRM_Cdntaxreceipts_Utils_Time {

  /**
   * Return either the mock time our test has set, or the real time.
   * @return int Unix timestamp
   */
  public static function time(): int {
    if (defined('CIVICRM_TEST')) {
      return (int) (\Civi::settings()->get('cdntaxreceipts_mocktime') ?? $_SERVER['REQUEST_TIME']);
    }
    return (int) $_SERVER['REQUEST_TIME'];
  }

  /**
   * Set the mock time.
   * @param string A string parseable by strtotime().
   */
  public static function setTime(string $t) {
    \Civi::settings()->set('cdntaxreceipts_mocktime', strtotime($t));
  }

  /**
   * Turn off mock time.
   */
  public static function reset() {
    \Civi::settings()->set('cdntaxreceipts_mocktime', NULL);
  }

  public static function couperChaine($string, $nbrecarectere){
    //$mot_wrap = wordwarp($string, $nbrecarectere, '#**#**#');
    $mots = explode(" ", $string);

    $retour[] = [];
    $iretour = 0;
    $ilen = 0;
    foreach($mots as $value){
        $ilen += strlen($values);
        if ($ilen < $nbrecarectere){
        $retour[$iretour] += $value;
        }else{
        $ilen = strlen($values);
        $iretour += $iRetour + 1;
        $retour[$iretour] = $value;
        }
    }

    return $retour;
  }

    
  /**
   * Transformer une date yyyy-MM-dd
   */
  public static function date_format_fr($date){
    $date = substr($date,8,2).'/'.substr($date,5,2).'/'.substr($dodaten_date,0,4);
  }

}
