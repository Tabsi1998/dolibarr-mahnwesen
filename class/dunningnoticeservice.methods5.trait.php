<?php
/* Auto-split method trait for maintainable source files. */
trait DunningNoticeServiceMethods5
{
    protected function asHtml($text){$text=(string)$text;if(preg_match('/<\s*(p|div|table|br|ul|ol|h[1-6]|strong|span|a)\b/i',$text))return $text;return '<p>'.nl2br(dol_escape_htmltag($text),false).'</p>';}
    protected function postalBlock($company){if(!is_object($company))return '';$lines=array();if(!empty($company->name))$lines[]=$company->name;if(!empty($company->address))$lines[]=$company->address;$city=trim((string)(!empty($company->zip)?$company->zip:'').' '.(string)(!empty($company->town)?$company->town:''));if($city!=='')$lines[]=$city;if(!empty($company->country))$lines[]=$company->country;return implode("\n",$lines);}
}
