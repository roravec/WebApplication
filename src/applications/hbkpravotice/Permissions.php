<?php

#[\AllowDynamicProperties]
class Permissions
{	
	// at least what admin level is needed to gain permissions
	// Use maximum admin level 5 (just suggestion)
	public function __construct(
		$cPermissionsLevel = 255, 
		$rPermissionsLevel = 255,
		$uPermissionsLevel = 255, 
		$dPermissionsLevel = 255
		)
	{
		$this->CreatePermissionsLevel = $cPermissionsLevel;
		$this->ReadPermissionsLevel = 	$rPermissionsLevel;
		$this->UpdatePermissionsLevel = $uPermissionsLevel;
		$this->DeletePermissionsLevel = $dPermissionsLevel;
	}
	public function UserHasPerm(
		$user, 
		$create = false, 
		$read = false, 
		$update = false, 
		$delete = false
		)
	{
		$userRightsLevel = $user->GetRightsLevel();
		if ($create)
		{
			if ($userRightsLevel < $this->CreatePermissionsLevel) return false;
		}
		if ($read)
		{
			if ($userRightsLevel < $this->ReadPermissionsLevel) return false;
		}
		if ($update)
		{
			if ($userRightsLevel < $this->UpdatePermissionsLevel) return false;
		}
		if ($delete)
		{
			if ($userRightsLevel < $this->DeletePermissionsLevel) return false;
		}
		return true;
	}
	private $CreatePermissionsLevel = 255;
	private $ReadPermissionsLevel = 255;
	private $UpdatePermissionsLevel = 255;
	private $DeletePermissionsLevel = 255;
}

?>