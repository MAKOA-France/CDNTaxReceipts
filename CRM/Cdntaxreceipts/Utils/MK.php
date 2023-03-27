<?php

class CRM_Cdntaxreceipts_Utils_MK {

 /*  public static function searchKitTasks(\Civi\Core\Event\GenericHookEvent $event) {

    // ajouter une action dans searchkit
     $event->tasks['Contact']['RecusFiscaux'] = [
      'module' => 'Cdntaxreceipts',
      'title' => 'Générer les reçus fiscaux',
      'icon' => 'fa-random',
      'class' => 'CRM_Cdntaxreceipts_Task_IssueAnnualTaxReceipts',
     // 'result' => TRUE,
     // 'uiDialog' => ['templateUrl' => '~/crmPhxCotisations/crmSearchTaskCotisRenew.html'],
    ];

} */

    public static function cutStringByWord($string, $nbrecarectere){
        //$mot_wrap = wordwarp($string, $nbrecarectere, '#**#**#');
        $string = str_replace("euros,","euros et", $string);
        $mots = explode(" ", $string);

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
            }else{
                $retour[$iretour] = $s;

                $ilen = $strlen+1;
                $iretour += $iRetour + 1;
                $s = '';
                $s .= $value.' ';
            }
        }
        $retour[$iretour] = $s;

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
        $contact = \Civi\Api4\Contact::get(FALSE)
            ->addSelect('contact_type')
            ->addWhere('id', '=', $contact_id)
            ->execute()
            ->first();
        $contact_type = $contact['contact_type'];

        return $contact_type;
    }

    Public static function mk_log($sFunctionName, $sVariable = '', $variable = null){
        return;
        // Civi::log()->info($sFunctionName.' '.$sVariable.' : '.print_r($variable,1));

    }
    public static function getOptionValueLabel($group_name, $value){
        // Civi::log()->info('getOptionValueLabel group_name : '.print_r($group_name,1).' value : '.print_r($value,1));
        $label = null;
        $optionValues = \Civi\Api4\OptionValue::get(FALSE)
            ->addWhere('option_group_id:name', '=', $group_name ) 
            ->addWhere('value', '=', $value)
            ->execute();
        foreach ($optionValues as $optionValue) {
            $label = $optionValue['label'];
        }
        // Civi::log()->info('getOptionValueLabel label : '.print_r($label,1));
        return $label;
    }
}
