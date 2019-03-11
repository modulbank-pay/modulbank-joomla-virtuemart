<?php
defined('_JEXEC') or die('Restricted access');

jimport('joomla.form.formfield');

class JFormFieldLogs extends JFormFieldText {

	var $type = 'logs';

	protected function getInput() {

		$result = parent::getInput();
		$result .= '<br><a href="'.JURI::getInstance()->toString().'&download_modulbank_logs=1">Скачать логи</a>';
		return $result;

	}

}