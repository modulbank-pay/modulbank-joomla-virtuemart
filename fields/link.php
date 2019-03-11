<?php
defined('_JEXEC') or die('Restricted access');

jimport('joomla.form.formfield');

class JFormFieldLink extends JFormFieldText {

	var $type = 'link';

	protected function getInput() {
		if($this->value == $this->default){
			$this->value = JUri::root().$this->default;
		}
		$result = parent::getInput();

		return $result;

	}

}