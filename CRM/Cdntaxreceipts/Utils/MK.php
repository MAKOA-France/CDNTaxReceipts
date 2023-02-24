<?php

class CRM_Cdntaxreceipts_Utils_MK {

    public static function cutStringByWord($string, $nbrecarectere){
        //$mot_wrap = wordwarp($string, $nbrecarectere, '#**#**#');
        $string = str_replace("euros,","euros et", $string);
        $mots = explode(" ", $string);
        // Civi::log()->info('CRM_Cdntaxreceipts_Utils_MK couperChaine > string : '.print_r($string ,1).' nbrecarectere : '.$nbrecarectere);
        // Civi::log()->info('CRM_Cdntaxreceipts_Utils_MK couperChaine > mots : '.print_r($mots ,1));

        $s = '';
        $retour= array();

        $iretour = 0;
        $ilen = 0;
        foreach($mots as $value){
            
            $strlen = strlen($value);
            $ilen += $strlen+1;
            // Civi::log()->info('CRM_Cdntaxreceipts_Utils_MK couperChaine > value : '.print_r($value ,1). ' strlen : '.$strlen. ' ilen : '.$ilen);
            if ($ilen < $nbrecarectere){
                $s .= $value.' ';
                // Civi::log()->info('CRM_Cdntaxreceipts_Utils_MK couperChaine > $s : '.print_r($s ,1));
            }else{
                // Civi::log()->info('CRM_Cdntaxreceipts_Utils_MK couperChaine > $sELSE : '.print_r($s ,1));
                // $mots_tiret = explode('-', $value);
                // Civi::log()->info('CRM_Cdntaxreceipts_Utils_MK couperChaine > $mots_tiret : '.print_r($mots_tiret ,1). ' count($mot_tiret) : '.count($mot_tiret));
                // if (count($mots_tiret) > 0 ){
                //     $ilen -= $strlen-1;
                //     Civi::log()->info('CRM_Cdntaxreceipts_Utils_MK couperChaine > mots_tiret.$ilen Moins : '.$ilen);
                //     foreach($mots_tiret as $val){
                //         $strlen_tiret = strlen($val);
                //         $ilen += $strlen_tiret+1;
                //         Civi::log()->info('CRM_Cdntaxreceipts_Utils_MK couperChaine > mots_tiret.$ilen : '.$ilen);
                //         if ($ilen < $nbrecarectere){
                //             $s .= $val.'-';
                //             Civi::log()->info('CRM_Cdntaxreceipts_Utils_MK couperChaine > $s5 : '.print_r($s ,1). '  $strlen_tiret : ' . $strlen_tiret.' $ilen : '.$ilen);

                //         }else{
                //             $retour[$iretour] = $s;
                //             $ilen = $strlen_tiret+1;
                //             $iretour += $iRetour + 1;
                //             $s = '';
                //             $s .= $val.'-';
                //             Civi::log()->info('CRM_Cdntaxreceipts_Utils_MK couperChaine > $s6 : '.print_r($s ,1). '  $strlen_tiret : ' . $strlen_tiret.' $ilen : '.$ilen);
                //         }
                        
                //     }
                // }else{
                    // Civi::log()->info('CRM_Cdntaxreceipts_Utils_MK couperChaine > $s2 : '.print_r($s ,1));
                    $retour[$iretour] = $s;
    
                    $ilen = $strlen+1;
                    $iretour += $iRetour + 1;
                    $s = '';
                    $s .= $value.' ';
                    // Civi::log()->info('CRM_Cdntaxreceipts_Utils_MK couperChaine > $s3 : '.print_r($s ,1));
                // }
            }
        }
        $retour[$iretour] = $s;

        // Civi::log()->info('CRM_Cdntaxreceipts_Utils_MK couperChaine > retour : '.print_r($retour ,1));
        return $retour;
    }

    
    /**
     * Transformer une date yyyy-MM-dd
     */
    public static function date_format_fr($date){
        $date = substr($date,8,2).'/'.substr($date,5,2).'/'.substr($date,0,4);
        return $date;
    }

    /**
     * get contact type
     * 
     * @param contact_id [INT] contact identifier
     * @return string
     */
    public static function get_contact_type($contact_id) {
        $contact_type = '';
        $contacts = \Civi\Api4\Contact::get(FALSE)
          ->addWhere('id', '=', $contact_id)
          ->execute();
        foreach ($contacts as $contact) {
          $contact_type = $contact['contact_type'];
          break;
        }
        // Civi::log()->info('get_contact_type contact_id : '.print_r($contact_id,1)); 
        // Civi::log()->info('get_contact_type contact_type : '.print_r($contact_type,1)); 

        return $contact_type;
    }

    Public static function mk_log($sFunctionName, $sVariable = '', $variable = null){
        return;
        // if (empty($variable)){
        //     Civi::log()->info(sFunctionName.' '.$sVariable.' : '.$variable);
        // }else{
            Civi::log()->info($sFunctionName.' '.$sVariable.' : '.print_r($variable,1));
        // }
        
      }
}