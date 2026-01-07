<?php
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes********************************* */
require_once __DIR__  . '/../../../../core/php/core.inc.php';

class energy3 extends eqLogic {
  /*     * *************************Attributs****************************** */


  public static $_period = array(
    'D' => array(
      'name' => 'J',
      'start' => 'midnight',
      'end' => 'tomorrow midnight -1 second',
    ),
    'D-1' => array(
      'name' => 'J-1',
      'start' => '-1 day midnight +1 second',
      'end' => 'today midnight -1 second',
    ),
    'W' => array(
      'name' => 'S',
      'start' => 'monday this week midnight',
      'end' => 'sunday 23:59:59',
    ),
    'W-1' => array(
      'name' => 'S-1',
      'start' => 'monday this week midnight -7 days',
      'end' => 'last sunday 23:59:59',
    ),
    'M' => array(
      'name' => 'M',
      'start' => 'first day of this month midnight',
      'end' => 'last day of this month 23:59:59',
    ),
    'M-1' => array(
      'name' => 'M-1',
      'start' => 'first day of previous month midnight',
      'end' => 'last day of previous month 23:59:59',
    ),
    'Y' => array(
      'name' => 'A',
      'start' => 'first day of january this year midnight',
      'end' => 'last day of december this year 23:59:59',
    ),
    'Y-1' => array(
      'name' => 'A-1',
      'start' => 'first day of january last year midnight',
      'end' => 'last day of december last year 23:59:59',
    ),
  );

  private static $_listen_cmd = array('elec::import', 'elec::consumption', 'elec::production::instant', 'elec::production', 'elec::export', 'gaz::consumption::instant', 'gaz::consumption', 'water::consumption::instant', 'water::consumption', 'elec::net::power');

  /*     * ***********************Methode static*************************** */


  public static function listenner($_options) {
    $eqLogic = energy3::byId($_options['energy3_id']);
    if (!is_object($eqLogic)) {
      return;
    }
    foreach (self::$_listen_cmd as $key) {
      if ($eqLogic->getConfiguration($key) == '') {
        continue;
      }
      if (strpos($eqLogic->getConfiguration($key), '#' . $_options['event_id'] . '#') !== false) {
        if ($key == 'elec::net::power') {
          $eqLogic->calculImportExport();
        } else {
          $eqLogic->checkAndUpdateCmd($key, jeedom::evaluateExpression($eqLogic->getConfiguration($key)));
        }
      }
    }
    $eqLogic->calculPerformance();
  }

  public static function cronHourly() {
    foreach (eqLogic::byType('energy3', true) as $eqLogic) {
      $eqLogic->calculSolarPrevision();
    }
  }



  /*     * *********************Méthodes d'instance************************* */

  public function calculSolarPrevision() {
    if ($this->getConfiguration('solar::forecast::lat') == '') {
      return;
    }
    if ($this->getConfiguration('solar::forecast::lon') == '') {
      return;
    }
    if ($this->getConfiguration('solar::forecast::orientation') == '') {
      return;
    }
    if ($this->getConfiguration('solar::forecast::inclination') == '') {
      return;
    }
    if ($this->getConfiguration('solar::forecast::power') == '') {
      return;
    }
    $url = 'https://api.forecast.solar/estimate/' . $this->getConfiguration('solar::forecast::lat') . '/' . $this->getConfiguration('solar::forecast::lon') . '/' . $this->getConfiguration('solar::forecast::inclination') . '/' . $this->getConfiguration('solar::forecast::orientation') . '/' . $this->getConfiguration('solar::forecast::power');
    $request_http = new com_http($url);
    $result = json_decode(trim($request_http->exec(20)), true);
    if (!isset($result['message'])) {
      return;
    }
    if ($result['message']['code'] != 0) {
      throw new Exception(__('Erreur lors de la récuperation des prévision solaire : ', __FILE__) . $result['message']['text']);
    }
    if (isset($result['result']['watt_hours_day'][date('Y-m-d')])) {
      $this->checkAndUpdateCmd('solar::forecast::today', $result['result']['watt_hours_day'][date('Y-m-d')]);
    }
    if (isset($result['result']['watt_hours_day'][date('Y-m-d', strtotime('now +1 day'))])) {
      $this->checkAndUpdateCmd('solar::forecast::tomorrow', $result['result']['watt_hours_day'][date('Y-m-d', strtotime('now +1 day'))]);
    }
    if (isset($result['result']['watts'][date('Y-m-d H:00:00', strtotime('now +1 hour'))])) {
      $this->checkAndUpdateCmd('solar::forecast::nexthour::power', $result['result']['watts'][date('Y-m-d H:00:00', strtotime('now +1 hour'))]);
    }
    foreach ($result['result']['watts'] as $datetime => $value) {
      if (strtotime($datetime) < strtotime('now')) {
        continue;
      }
      $this->checkAndUpdateCmd('solar::forecast::now::power', $value, $datetime);
    }
    if (isset($result['result']['watts'][date('Y-m-d H:00:00')])) {
      $this->checkAndUpdateCmd('solar::forecast::now::power', $result['result']['watts'][date('Y-m-d H:00:00')]);
    }
  }


  public function calculImportExport() {
    $net_power = (float) jeedom::evaluateExpression($this->getConfiguration('elec::net::power'));
    $elec_production = (float) jeedom::evaluateExpression($this->getConfiguration('elec::production::instant'));
    if ($net_power > 0) {
      $this->checkAndUpdateCmd('elec::import::instant', $net_power);
      $this->checkAndUpdateCmd('elec::export::instant', 0);
    } else {
      $this->checkAndUpdateCmd('elec::import::instant', 0);
      $this->checkAndUpdateCmd('elec::export::instant', -$net_power);
    }
    if ($elec_production > 0) {
      if ($net_power > 0) {
        $this->checkAndUpdateCmd('elec::production::consumption::instant', $elec_production);
      } else {
        $this->checkAndUpdateCmd('elec::production::consumption::instant', $elec_production + $net_power);
      }
    } else {
      $this->checkAndUpdateCmd('elec::production::consumption::instant', 0);
    }
    if (!is_numeric($net_power)) {
        $net_power = 0;
    }
    if (!is_numeric($elec_production)) {
        $elec_production = 0;
    }
    $this->checkAndUpdateCmd('elec::consumption::instant', $elec_production + $net_power);
  }

  public function calculPerformance() {
    $production = (float) $this->getCmd('info', 'elec::production')->execCmd();
    $export = (float) $this->getCmd('info', 'elec::export')->execCmd();
    $consumption = (float) $this->getCmd('info', 'elec::consumption')->execCmd();
    if ($production > 0) {
      $autoconsumption = round((($production - $export) / $production) * 100, 1);
    }else {
      $autoconsumption = 0;
    }
    if ($autoconsumption < 0) {
      $autoconsumption = 0;
    } elseif ($autoconsumption > 100) {
      $autoconsumption = 100;
    }
    $this->checkAndUpdateCmd('elec::autoconsumption', $autoconsumption);
    if($consumption > 0){
      $selfsufficiency = round((($production - $export) / $consumption) * 100, 1);
      if ($selfsufficiency < 0) {
        $selfsufficiency = 0;
      } elseif ($selfsufficiency > 100) {
        $selfsufficiency = 100;
      }
      $this->checkAndUpdateCmd('elec::selfsufficiency', $selfsufficiency);
    }
  }


  public function toHtml($_version = 'dashboard') {
    $replace = $this->preToHtml($_version);
    if (!is_array($replace)) {
      return $replace;
    }
    $version = jeedom::versionAlias($_version);
    if ($this->getConfiguration('refresh') == '') {
      $replace['#refresh_id#'] = '';
    }
    $replace['#version#'] = $_version;
    foreach (self::$_listen_cmd as $key) {
      $replace['#' . str_replace('::', '-', $key) . '-id#'] = '';
    }
    foreach ($this->getCmd('info') as $cmd) {
      $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-id#'] = $cmd->getId();
      if (in_array($cmd->getLogicalId(), self::$_listen_cmd) && $this->getConfiguration($cmd->getLogicalId()) == '') {
        $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-state#'] = '';
        $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-state#'] = '';
        $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-valueDate#'] = '';
        $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-collectDate#'] = '';
        $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-unite#'] = '';
        continue;
      }
      $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-state#'] = $cmd->execCmd();
      if ($replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-state#'] == '') {
        $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-state#'] = 0;
      }
      $valueInfo = cmd::autoValueArray($replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-state#'], 2, $cmd->getUnite());
      $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-state#'] = $valueInfo[0];
      $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-valueDate#'] = $cmd->getValueDate();
      $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-collectDate#'] = $cmd->getCollectDate();
      $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-unite#'] = $valueInfo[1];
    }
    return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'eqLogic', __CLASS__)));
  }

  public function preSave() {
    if ($this->getConfiguration('solar::forecast::lat') == '') {
      $this->setConfiguration('solar::forecast::lat', config::byKey('info::latitude'));
    }
    if ($this->getConfiguration('solar::forecast::lon') == '') {
      $this->setConfiguration('solar::forecast::lon', config::byKey('info::longitude'));
    }
    if ($this->getConfiguration('consumption::instant') != '') {
      $this->setConfiguration('consumption::instant', null);
    }
    if ($this->getConfiguration('consumption::byday') != '') {
      $this->setConfiguration('consumption::byday', null);
    }
    if ($this->getConfiguration('consumption::cost') != '') {
      $this->setConfiguration('consumption::cost', null);
    }
    if ($this->getConfiguration('gaz::instant') != '') {
      $this->setConfiguration('gaz::instant', null);
    }
    if ($this->getConfiguration('gaz::cost') != '') {
      $this->setConfiguration('gaz::cost', null);
    }
    if ($this->getConfiguration('gaz::byday') != '') {
      $this->setConfiguration('gaz::byday', null);
    }
    if ($this->getConfiguration('water::instant') != '') {
      $this->setConfiguration('water::instant', null);
    }
    if ($this->getConfiguration('water::cost') != '') {
      $this->setConfiguration('water::cost', null);
    }
    if ($this->getConfiguration('water::byday') != '') {
      $this->setConfiguration('water::byday', null);
    }
    if ($this->getConfiguration('refreshr') != '') {
      $this->setConfiguration('refreshr', null);
    }
    if ($this->getConfiguration('tempeture::ext') != '') {
      $this->setConfiguration('tempeture::ext', null);
    }
    $this->setConfiguration('panelLink', 'panel');
  }

  public function postSave() {
    $cmds = json_decode(file_get_contents(__DIR__ . '/../config/cmd.json'), true);
    foreach ($cmds as $key => $cmd_info) {
      if (in_array($key, array('gaz::consumption::instant', 'gaz::consumption', 'water::consumption::instant', 'water::consumption')) && $this->getConfiguration($key) == '') {
        $cmd = $this->getCmd(null, $key);
        if (is_object($cmd)) {
          $cmd->remove();
        }
        continue;
      }
      $cmd = $this->getCmd(null, $key);
      if (!is_object($cmd)) {
        $cmd = new energy3Cmd();
        $cmd->setIsVisible($cmd_info['isVisible']);
        $cmd->setUnite($cmd_info['unite']);
        $cmd->setName($cmd_info['name']);
        $cmd->setIsHistorized($cmd_info['isHistorized']);
        $cmd->setConfiguration('historizeRound', 2);
      }
      $cmd->setLogicalId($key);
      $cmd->setEqLogic_id($this->getId());
      $cmd->setType($cmd_info['type']);
      $cmd->setSubType($cmd_info['subtype']);
      $cmd->save();
    }
    $events = array();
    foreach (self::$_listen_cmd as $key) {
      if ($this->getConfiguration($key) != '') {
        preg_match_all("/#([0-9]*)#/", $this->getConfiguration($key), $matches);
        foreach ($matches[1] as $cmd_id) {
          $events[] = $cmd_id;
        }
        $cmd = $this->getCmd(null, $key);
        if (is_object($cmd)) {
          $cmd->event(jeedom::evaluateExpression($this->getConfiguration($key)));
        }
      }
    }

    $listener = listener::byClassAndFunction(__CLASS__, 'listenner', array('energy3_id' => intval($this->getId())));
    if (!is_object($listener)) {
      $listener = new listener();
    }
    $listener->setClass('energy3');
    $listener->setFunction('listenner');
    $listener->setOption(array('energy3_id' => intval($this->getId())));
    $listener->emptyEvent();
    if(is_array($events) && count($events) > 0){
      foreach ($events as $cmd_id) {
        $listener->addEvent($cmd_id);
      }
    }
    $listener->save();

    $this->calculImportExport();
    $this->calculPerformance();
    try {
      $this->calculSolarPrevision();
    } catch (\Throwable $th) {
    }

    $nbConsummer = count($this->getConfiguration('elecConsumers'));
	if(is_array($this->getCmd('info')) && count($this->getCmd('info')) > 0){
	    foreach ($this->getCmd('info') as $cmd) {
	      if (strpos($cmd->getLogicalId(), 'elec-consummer-') !== 0) {
	        continue;
	      }
	      $cmd->remove();
	    }
	}
    if(is_array($this->getConfiguration('elecConsumers')) && count($this->getConfiguration('elecConsumers')) > 0){
	    foreach ($this->getConfiguration('elecConsumers') as $elecConsumer) {
	      $consumer = cmd::byId(str_replace('#', '', $elecConsumer['cmd']));
	      if (is_object($consumer) && $consumer->getIsHistorized() != 1) {
	        $consumer->setIsHistorized(1);
	        $consumer->save();
	      }
	    }
	  }
  }
	  
  public function generatePanel($_version = 'dashboard', $_period = 'D') {
    $starttime = date('Y-m-d H:i:s', strtotime(self::$_period[$_period]['start']));
    $endtime = date('Y-m-d H:i:s', strtotime(self::$_period[$_period]['end']));
    $return = array(
      'widget' => '',
      'data' => array(
        'cmd' => array(),
        'datetime' => array(
          'start' => date('Y-m-d H:i:s', strtotime(self::$_period[$_period]['start'] . ' +30 minutes')),
          'end' => $endtime,
          'end_1' => date('Y-m-d H:i:s', strtotime(self::$_period[$_period]['end'] . ' + 1 day')),
          'period' => $_period
        )
      )
    );
    if ($_period == '') {
      $_period = 'D';
    }
    foreach ($this->getCmd('info') as $cmd) {
      $return['data']['cmd'][$cmd->getLogicalId()] = array('id' => $cmd->getId());
    }

    $return['data']['cmd']['temperature::ext'] = array('id' => str_replace('#', '', $this->getConfiguration('temperature::ext')));


    config::save('savePeriod', $_period, 'energy3');
    $return['html'] = '<center>';
    foreach (self::$_period as $key => $value) {
      if ($_period == $key) {
        if ($_version == 'dashboard') {
          $return['html'] .= '<a class="btn btn-success bt_changePeriod" data-period="' . $key . '">' . $value['name'] . '</a> ';
        } else {
          $return['html'] .= '<a class="ui-btn ui-btn-raised ui-btn-inline bt_changePeriod" data-period="' . $key . '">' . $value['name'] . '</a> ';
        }
      } else {
        if ($_version == 'dashboard') {
          $return['html'] .= '<a class="btn btn-default bt_changePeriod" data-period="' . $key . '">' . $value['name'] . '</a> ';
        } else {
          $return['html'] .= '<a class="ui-btn ui-btn ui-mini ui-btn-inline bt_changePeriod" data-period="' . $key . '" style="color:rgb(90,90,90) !important">' . $value['name'] . '</a> ';
        }
      }
    }
    $return['html'] .= '</center>';
    if ($_version == 'dashboard') {
      $return['html'] .= '<div class="row">';
      $return['html'] .= '<div class="col-lg-3 col-md-6 col-sm-6 col-xs-12">';
      $return['html'] .= '<legend>Etat</legend>';
      $return['html'] .= '<div class="div_eqLogicEnergy3">';
    } else {
      $return['html'] .= '<legend>Etat</legend>';
      $return['html'] .= '<div class="objectHtml">';
    }
    if ($_period == 'D') {
      $return['html'] .= $this->toHtml($_version);
      $elec_consumption = (float) $this->getCmd('info', 'elec::consumption')->execCmd();
    } else {
      $replace = $this->preToHtml($_version);
      $version = jeedom::versionAlias($_version);
      $replace['#version#'] = $_version;
      foreach (self::$_listen_cmd as $key) {
        $replace['#' . str_replace('::', '-', $key) . '-id#'] = '';
      }
      foreach ($this->getCmd('info') as $cmd) {
        $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-id#'] = $cmd->getLogicalId();
        if (in_array($cmd->getLogicalId(), self::$_listen_cmd) && $this->getConfiguration($cmd->getLogicalId()) == '') {
          $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-state#'] = '';
          $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-state#'] = '';
          $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-valueDate#'] = '';
          $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-collectDate#'] = '';
          $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-unite#'] = '';
          continue;
        }
        $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-valueDate#'] = '';
        $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-collectDate#'] = '';
        if (in_array($cmd->getLogicalId(), array('elec::production::instant', 'gaz::consumption::instant', 'water::consumption::instant', 'elec::net::power', 'elec::import::instant', 'elec::export::instant', 'elec::production::consumption::instant'))) {
          $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-state#'] = '';
          $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-unite#'] = '';
          continue;
        }

        if (in_array($cmd->getLogicalId(), array('elec::autoconsumption', 'elec::selfsufficiency'))) {
          $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-state#'] = $this->getValueForPeriod($cmd->getId(), 'AVG', $starttime, $endtime);
        } else {
          $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-state#'] = $this->getValueForPeriod($cmd->getId(), 'SUM', $starttime, $endtime);
        }
        if ($cmd->getLogicalId() == 'elec::consumption') {
          $elec_consumption = $replace['#elec-consumption-state#'];
        }else{
          $elec_consumption = 0;
        }
        if ($replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-state#'] == '') {
          $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-state#'] = 0;
        }
        $valueInfo = cmd::autoValueArray($replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-state#'], 2, $cmd->getUnite());
        $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-state#'] = $valueInfo[0];
        $replace['#' . str_replace('::', '-', $cmd->getLogicalId()) . '-unite#'] = $valueInfo[1];
      }
      $replace['#refresh_id#'] = '';
      $return['html'] .= $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'eqLogic', __CLASS__)));
    }
    $display_elec_production = ($this->getConfiguration('elec::production') == '') ? 'display:none;' : '';
    $display_water_production = ($this->getConfiguration('water::consumption') == '') ? 'display:none;' : '';
    $display_gas_production = ($this->getConfiguration('gaz::consumption') == '') ? 'display:none;' : '';
    $display_elec_details = (count($this->getConfiguration('elecConsumers')) == 0) ? 'display:none;' : '';
    if ($_version == 'dashboard') {
      $return['html'] .= '</div>';
      $return['html'] .= '</div>';
      if ($_period == 'D' || $_period == 'D-1') {
        $return['html'] .= '<div class="col-lg-5 col-md-6 col-sm-6 col-xs-12" style="' . $display_elec_production . '">';
        $return['html'] .= '<legend>Prévision</legend>';
        $return['html'] .= '<div class="chartContainer" id="div_energy3GraphForecast" style="height:300px;"></div>';
        $return['html'] .= '</div>';
      } else {
        $return['html'] .= '<div class="col-lg-5 col-md-6 col-sm-6 col-xs-12" style="' . $display_elec_production . '">';
        $return['html'] .= '<legend>Performance production électrique</legend>';
        $return['html'] .= '<div class="chartContainer" id="div_energy3GraphElecAuto" style="height:300px;"></div>';
        $return['html'] .= '</div>';
      }
      $return['html'] .= '<div class="col-lg-4 col-md-12 col-sm-12 col-xs-12" style="' . $display_elec_details . '">';
      $return['html'] .= '<legend>Détails Electricité</legend>';
      $return['html'] .= '<div id="div_energy3ElecConsumers" style="height:300px;overflow:auto;"></div>';
      $return['html'] .= '</div>';
      $return['html'] .= '<div class="col-lg-12 col-xs-12">';
      $return['html'] .= '<legend>Consommation/Production</legend>';
      $return['html'] .= '<div class="chartContainer" id="div_energy3GraphConsumptionProduction"></div>';
      $return['html'] .= '</div>';
      $return['html'] .= '<div class="col-lg-6 col-sm-6 col-xs-12" style="' . $display_gas_production . '">';
      $return['html'] .= '<legend>Gaz</legend>';
      $return['html'] .= '<div class="chartContainer" id="div_energy3GraphGas"></div>';
      $return['html'] .= '</div>';
      $return['html'] .= '<div class="col-lg-6 col-sm-6 col-xs-12" style="' . $display_water_production . '">';
      $return['html'] .= '<legend>Eau</legend>';
      $return['html'] .= '<div class="chartContainer" id="div_energy3GraphWater"></div>';
      $return['html'] .= '</div>';
      $return['html'] .= '</div>';
    } else {
      $return['html'] .= '</div>';
      if ($_period == 'D'  || $_period == 'D-1') {

        $return['html'] .= '<legend style="' . $display_elec_production . '">Prévision</legend>';
        $return['html'] .= '<div class="chartContainer" id="div_energy3GraphForecast" style="' . $display_elec_production . '"></div>';
      } else {
        $return['html'] .= '<legend style="' . $display_elec_production . '">Performance production électrique</legend>';
        $return['html'] .= '<div style="' . $display_elec_production . '" class="chartContainer" id="div_energy3GraphElecAuto"></div>';
      }
      $return['html'] .= '<div>';
      $return['html'] .= '<legend>Consommation/Production</legend>';
      $return['html'] .= '<div class="chartContainer" id="div_energy3GraphConsumptionProduction"></div>';
      $return['html'] .= '</div>';
      $return['html'] .= '<div>';
      $return['html'] .= '<legend style="' . $display_gas_production . '">Gaz</legend>';
      $return['html'] .= '<div class="chartContainer" id="div_energy3GraphGas" style="' . $display_gas_production . '"></div>';
      $return['html'] .= '</div>';
      $return['html'] .= '<div>';
      $return['html'] .= '<legend style="' . $display_water_production . '">Eau</legend>';
      $return['html'] .= '<div class="chartContainer" id="div_energy3GraphWater" style="' . $display_water_production . '"></div>';
      $return['html'] .= '</div>';
      $return['html'] .= '<div>';
      $return['html'] .= '<legend style="' . $display_elec_details . '">Détails Electricité</legend>';
      $return['html'] .= '<div id="div_energy3ElecConsumers" style="background-color: rgba(var(--eq-bg-color), var(--opacity)) !important;' . $display_elec_details . '"></div>';
      $return['html'] .= '</div>';
    }
    if (in_array(strtolower($this->getCmd('info', 'elec::consumption')->getUnite()), array('wh', 'w'))) {
      $elec_consumption = $elec_consumption / 1000;
    }
    $array_elec_consumers = array();
    foreach ($this->getConfiguration('elecConsumers') as $elecConsumer) {
      $consumer = cmd::byId(str_replace('#', '', $elecConsumer['cmd']));
      if (!is_object($consumer)) {
        continue;
      }
      $consumption = $this->getConsummerDayConsumption($elecConsumer, $starttime, $endtime);
      $info = array(
        'id' => $consumer->getId(),
        'value' => $consumption,
        'unit' => 'kWh'
      );

      $info['name'] = (!isset($elecConsumer['name']) || $elecConsumer['name'] == '') ? $consumer->getEqLogic()->getName() : $elecConsumer['name'];
      if ($consumption == 0 || $elec_consumption == 0) {
        $info['pourcent'] = 0;
      } else {
        $info['pourcent'] = round(($consumption / $elec_consumption) * 100);
        if ($info['pourcent'] > 100) {
          $info['pourcent'] = 100;
        }
        if ($info['pourcent'] < 0) {
          $info['pourcent'] = 0;
        }
      }
      $array_elec_consumers[] = $info;
    }
    usort($array_elec_consumers, function ($a, $b){
        if ($a["value"] == $b["value"]){
            return 0;
        }
        return ($a["value"] >  $b["value"]) ? -1 : 1;
    });

    $return['data']['cmd']['consumer::elec'] = $array_elec_consumers;
    return $return;
  }

  public function getConsummerDayConsumption($_elecConsumer, $_starttime, $_endtime) {
    $consumer = cmd::byId(str_replace('#', '', $_elecConsumer['cmd']));
    if (!is_object($consumer)) {
      return 0;
    }
    if (strtolower($consumer->getUnite()) == 'w' || strtolower($consumer->getUnite()) == 'kw') {
      if (strtotime($_endtime) > strtotime('now')) {
        $duration  = (strtotime('now') - strtotime($_starttime)) / (60 * 60);
      } else {
        $duration  = (strtotime($_endtime) - strtotime($_starttime)) / (60 * 60);
      }
      $consumption = ($consumer->getTemporalAvg($_starttime, $_endtime)) * $duration;
      if (strtolower($consumer->getUnite()) == 'w') {
        $consumption = $consumption / 1000;
      }
    } else {
      if ($_elecConsumer['consumptionByDay'] == 0) {
        $stats = $consumer->getStatistique($_starttime, $_endtime);
        $consumption = $stats['max'] - $stats['min'];
      } else {
        $begin = strtotime($_starttime);
        $end  = strtotime($_endtime);
        $consumption = 0;
        while ($begin < $end) {
          $stats = $consumer->getStatistique(date('Y-m-d H:i:s', $begin), date('Y-m-d H:i:s', $begin + ((24 * 60 * 60) - 1)));
          $consumption += $stats['max'];
          $begin += (24 * 60 * 60);
        }
        if (strtolower($consumer->getUnite()) == 'wh') {
          $consumption = $consumption / 1000;
        }
      }
    }
    return round($consumption, 2);
  }

  public function getValueForPeriod($_cmd_id, $_type, $_startTime, $_endTime) {
    $values = array(
      'cmd_id' => $_cmd_id,
      'startTime' => $_startTime,
      'endTime' => $_endTime,
    );
    $sql = 'SELECT ' . $_type . '(CAST(value AS DECIMAL(12,2))) as result
		FROM (
			SELECT *
			FROM history
			WHERE cmd_id=:cmd_id
      AND `datetime` IN (
        SELECT MAX(`datetime`)
        FROM history
        WHERE cmd_id=:cmd_id
			    AND `datetime`>=:startTime
			    AND `datetime`<=:endTime
        GROUP BY date(`datetime`)
      )
			GROUP BY date(`datetime`)
			UNION ALL
			SELECT *
			FROM historyArch
			WHERE cmd_id=:cmd_id
			AND `datetime` IN (
        SELECT MAX(`datetime`)
        FROM historyArch
        WHERE cmd_id=:cmd_id
			    AND `datetime`>=:startTime
			    AND `datetime`<=:endTime
        GROUP BY date(`datetime`)
      )
		) as dt';
    $result = DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW);
    return $result['result'];
  }


  /*     * **********************Getteur Setteur*************************** */
}

class energy3Cmd extends cmd {
  /*     * *************************Attributs****************************** */


  /*     * ***********************Methode static*************************** */


  /*     * *********************Methode d'instance************************* */


  // Exécution d'une commande
  public function execute($_options = array()) {
    $eqLogic = $this->getEqLogic();
    if ($this->getLogicalId() == 'refresh') {
      preg_match_all("/#([0-9]*)#/", $eqLogic->getConfiguration('refresh'), $matches);
      foreach ($matches[1] as $cmd_id) {
        $cmd = cmd::byId($cmd_id);
        if (is_object($cmd)) {
          $cmd->execCmd();
        }
      }
    }
  }

  /*     * **********************Getteur Setteur*************************** */
}
