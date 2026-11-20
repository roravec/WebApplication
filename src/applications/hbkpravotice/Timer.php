<?php
include_once __DIR__ . "/Database/DbObj.php";
include_once __DIR__ . "/Database/DbObj_Config.php";
include_once __DIR__ . "/Database/DbObj_Timer.php";
include_once __DIR__ . "/Database/DbObj_Gameevent.php";
include_once __DIR__ . "/Database/DbObj_Timerevent.php";

#[\AllowDynamicProperties]
class Countdown
{
	public $timerActive=0;
	public $team1name="";
	public $team1score=0;
	public $team2name="";
	public $team2score=0;
	public $countdownEvents = array();
}

#[\AllowDynamicProperties]
class CountdownEvent
{
	public function __construct($event)
	{
		$this->event = $event;
	}

	public $event=0;
	public $paralelEvent = 0;
	public $lastRecordedTime=0;
	public $startTimestamp=0;
	public $endTimestamp=0;
	public $timestampOffset=0;
	public $duration=0;
	public $remainingTime = 0;
	public $periodNumber=0;
	public $type = 0;
	public $name= "";
}
#[\AllowDynamicProperties]
class Timer
{
	public static $TABLE_NAME = "timer";
	public $StadiumControl = null;
	public $Database = null;
	public $DbPrefix = null;
	public $Config = null;

	private $SoundFilename_OneMinuteLeft = "lastMinute.mp3";
	private $TimerEventTypeToPlayAlmostEnd = 1;
	private $SoundFilename_PenaltyStart = "penaltyStart.mp3";
	private $SoundFilename_PenaltyEnds = "penaltyEnd.mp3";
	private $LastMinute_secondsRemaining = 60;
	public function __construct($stadiumControl, $database, $dbprefix)
	{
		$this->StadiumControl = $stadiumControl;
		$this->Database = $database;
		$this->DbPrefix = $dbprefix;
	}
	public function IsTimerRunning()
	{
		if ($this->StadiumControl->LocationConfig->timerActive)
			return true;
		else
			return false;
	}

	public function GetTime()
	{
		return time();
	}
	public function GetAppConfig()
	{
		return $this->StadiumControl->LocationConfig;
	}

	public function IsActiveGame()
	{
		if ($this->GetAppConfig()->activeGame > 0)
			return true;
		return false;
	}

	public function GetActiveGame()
	{
		return $this->StadiumControl->GetGame(
			$this->GetAppConfig()->activeGame
		);
	}
	public function GetActiveGameEvent()
	{
		return $this->StadiumControl->GetGameEventByGameid(
			$this->GetAppConfig()->activeGame
		);
	}
	public function GetActiveGameEvents()
	{
		return $this->StadiumControl->GetGameevents(
			$this->GetAppConfig()->activeGame
		);
	}
	public function GetActiveGameEventPenalties()
	{
		return $this->StadiumControl->GetGameeventPenalties(
			$this->GetAppConfig()->activeGame
		);
	}
	public function GetActiveGameTimerEvent()
	{
		$gameevent = $this->GetActiveGameEvent();
		return $this->StadiumControl->GetTimerevent($gameevent->event);
	}
	public function GetActiveGameTimerEvents()
	{
		return $this->StadiumControl->GetTimerEvents(
			$this->GetAppConfig()->activeGame
		);
	}
	public function GetGameeventsTimerevent($gameevent)
	{
		return $this->StadiumControl->GetTimerevent($gameevent->event);
	}
	public function IsThisLastEvent()
	{
		if ($this->GetActiveGameNextGameEvent() == null)
			return true;
		return false;
	}
	// This method refreshes time in gameevents
	public function SaveGameEventStatus($force = false)
	{
		if ($this->IsTimerRunning() || $force)
		{
			$dbobjGE = $this->GetActiveGameEvent();
			$dbobjTE = $this->GetGameeventsTimerevent($dbobjGE);
			$dbobjGE->lastRecordedTime = $this->GetTime();
			// Handle time of penalties
			if ($dbobjTE->type == 1) // penalty ticks only in GAME timer type
			{
				$penaltyGameEvents = $this->GetActiveGameEventPenalties();
				foreach ($penaltyGameEvents as $pGameEvent)
				{
					if ($pGameEvent->eventTimeStart == 0) // timer wasnt started yet
					{
						// let's start it
						$this->StartGameEvent($pGameEvent);
					}
					$pGameEvent->lastRecordedTime = $dbobjGE->lastRecordedTime;
					$pGameEvent->Update();
				}
			}
			$dbobjGE->Update();
		}
	}
	public function SetActiveGameGameEvent($newEvent)
	{
		if ($newEvent == null)
			return;
		if (!$this->IsActiveGame()) // no active game
			return;
		$dbobjGE = $this->GetActiveGameEvent();
		$dbobjGE->Read();
		$dbobjGE->event = $newEvent->id;
		$dbobjGE->eventTimeStart = 0;
		$dbobjGE->eventTimeStop = 0;
		$dbobjGE->eventTimeOffset = 0;
		$dbobjGE->startSoundPlayed = 0;
		$dbobjGE->almostEndSoundPlayed = 0;
		$dbobjGE->Update();
	}

	public function GetActiveGamePreviousGameEvent()
	{
		if (!$this->IsActiveGame()) // no active game
			return null;
		$events = $this->GetActiveGameTimerEvents();
		$i=0;
		foreach ($events as $event)
		{
			if ($event->id == $this->GetActiveGameEvent()->event)
			{
				if ($i>0)
				{
					return $events[$i-1];
				}
				else
				{
					return $event;
				}
			}
			$i++;
		}
		return null;
	}
	public function GetActiveGameNextGameEvent()
	{
		if (!$this->IsActiveGame()) // no active game
			return null;
		$events = $this->GetActiveGameTimerEvents();
		$i=0;
		foreach ($events as $event)
		{
			if ($event->id == $this->GetActiveGameEvent()->event)
			{
				if (count($events) > ($i+1))
				{
					return $events[$i+1];
				}
				else
				{
					return null; // at the end
				}
			}
			$i++;
		}
		return null;
	}
	public function GetActiveGameFirstGameEvent()
	{
		if (!$this->IsActiveGame()) // no active game
			return null;
		$events = $this->GetActiveGameTimerEvents();
		if (count($events) > 0)
			return $events[0];
		return null;
	}

	public function Tick()
	{
		if (!$this->IsActiveGame()) // no active game
			return;
		$this->SaveGameEventStatus();
		// check if how much time until end of event
		// and do proper action
		$this->ActiveEventRemainingTimeHandler();
	}

	public function ActiveEventRemainingTimeHandler()
	{
		if (!$this->IsActiveGame()) // no active game
			return;
		if (!$this->IsTimerRunning()) // timer is stopped so dont need to handle anything
			return;
		// get all games gameevents here
		$gameevents = $this->GetActiveGameEvents();
		foreach ($gameevents as $gameevent)
		{
			$timerevent = $this->GetGameeventsTimerevent($gameevent);
			$timeRemaining = $gameevent->eventTimeStop - $this->GetTime();
			if ($timeRemaining <= 0 && $gameevent->eventTimeStart > 0) // event ended
			{
				if ($gameevent->startSoundPlayed == 0)
				{
					$gameevent->startSoundPlayed = 1;
					$gameevent->Update();
					$this->PlayEventSound_End($timerevent);
				}
				if (!$gameevent->paralelEvent) // main event
				{
					$this->Stop();
					$this->NextEvent(); // go to next event
				}
			}
			else if (
				$timeRemaining <= $this->LastMinute_secondsRemaining
				&& $gameevent->almostEndSoundPlayed == 0
				&& $timerevent->type == $this->TimerEventTypeToPlayAlmostEnd
				&& $gameevent->eventTimeStart > 0
			)
			{
				$gameevent->almostEndSoundPlayed = 1;
				$gameevent->Update();
				$this->PlayEventSound_AlmostEnd();
			}
		}
	}

	// Notify timer that active game has changed
	// This method is called from StadiumControl when active game changes
	public function ActiveGameChanged($newGameId)
	{
		$this->SaveGameEventStatus();
		$this->Stop();
		if ($newGameId == 0) // new game is not active game
		{
		}
		else // handle game switch
		{
		}
	}
	public function StartStop()
	{
		if (!$this->IsActiveGame()) // no active game
			return;
		if ($this->GetAppConfig()->timerActive == 0) // timer is OFF, start it
		{
			$this->Start();
		}
		else // timer is ON, stop it
		{
			$this->Stop();
		}
	}
	public function Start()
	{
		if (!$this->IsActiveGame()) // no active game
			return;
		//$this->SaveGameEventStatus();
		//$this->Tick();

		$gameevents = $this->GetActiveGameEvents();
		foreach ($gameevents as $gameevent) 
		{
			$this->StartGameEvent($gameevent);
		}
		$this->GetAppConfig()->timerActive = 1;
		$this->GetAppConfig()->Update();
	}
	public function StartGameEvent($gameevent)
	{
		$timerevent = $this->GetGameeventsTimerevent($gameevent);
		if ($gameevent->eventTimeStart > 0) // game was running before and was stopped
		{
			// calculate offset
			$gameevent->eventTimeOffset += 
				$this->GetTime() - $gameevent->lastRecordedTime;
		}
		else // first event start
		{
			$this->PlayEventSound_Start($timerevent);
			$gameevent->eventTimeStart = $this->GetTime();
		}
		$gameevent->eventTimeStop = 
			$gameevent->eventTimeStart + 
			$timerevent->duration +
			$gameevent->eventTimeOffset;
		$gameevent->lastRecordedTime = $this->GetTime();
		$gameevent->Update();
	}
	public function Stop()
	{
		if (!$this->IsTimerRunning()) 
			return;
		if (!$this->IsActiveGame()) // no active game
			return;
		$this->SaveGameEventStatus();
		$this->GetAppConfig()->timerActive = 0;
		$this->GetAppConfig()->Update();
	}
	public function NextEvent($alwaysStopAfter = false)
	{
		$this->SaveGameEventStatus();
		if (!$this->IsActiveGame()) // no active game
			return;
		$this->Stop();
		$previousTimerEvent = $this->GetActiveGameTimerEvent();
		$nextEvent = $this->GetActiveGameNextGameEvent();
		if ($nextEvent == null) // no next event
		{
			$this->Stop();
		}
		else
		{
			$this->SetActiveGameGameEvent(
				$nextEvent
			);
			if ($previousTimerEvent->stopTimerAfter > 0 || $alwaysStopAfter)
			{
				$this->Stop();
			}
			else
			{
				$this->Start();
			}
		}
	}
	public function PreviousEvent()
	{
		$this->SaveGameEventStatus();
		if (!$this->IsActiveGame()) // no active game
			return;
		$this->Stop();
		$this->SetActiveGameGameEvent(
			$this->GetActiveGamePreviousGameEvent()
		);
	}
	public function ResetEventSequence()
	{
		$this->SaveGameEventStatus();
		if (!$this->IsActiveGame()) // no active game
			return;
		$this->Stop();
		$this->SetActiveGameGameEvent(
			$this->GetActiveGameFirstGameEvent()
		);
		$this->SaveGameEventStatus();
		/*$this->StadiumControl->ClearGameEvents(
			$this->StadiumControl->LocationConfig->activeGame,
			true
		);*/ // uncomment this if you want to delete penalty timers with reset
	}
	public function AddEvent()
	{
		if (!$this->IsActiveGame()) // no active game
			return;
	}

	public function AddTime($timeSub)
	{
		if (!$this->IsActiveGame()) // no active game
			return;
		//echo 'Time:'.$timeSub;exit;
		$gameevent = $this->GetActiveGameEvent();
		$timerevent = $this->GetActiveGameTimerEvent();
		/*if ($gameevent->eventTimeStop != 0) // event already started
		{
			if ($gameevent == null) return;
			$timeAfterSum = $gameevent->eventTimeStop + $timeSub;
			if ($timeAfterSum < 0)	$timeAfterSum = 0;
			$gameevent->eventTimeStop = $timeAfterSum;
			$gameevent->Update();
		}
		else*/
		{
			if ($timerevent == null) return;
			$timeAfterSum = $timerevent->duration + $timeSub;
			if ($timeAfterSum < 0)	$timeAfterSum = 0;
			$timerevent->duration = $timeAfterSum;
			$timerevent->Update();
		}
	}

	public function AddRemainingTime($timeSub)
	{
		if (!$this->IsActiveGame()) // no active game
			return;
		//echo 'Time:'.$timeSub;exit;
		$gameevent = $this->GetActiveGameEvent();
		$timerevent = $this->GetActiveGameTimerEvent();
		if ($gameevent->eventTimeStop != 0) // event already started
		{
			if ($gameevent == null) return;
			$timeAfterSum = $gameevent->eventTimeStop + $timeSub;
			if ($timeAfterSum < 0)	$timeAfterSum = 0;
			$gameevent->eventTimeStop = $timeAfterSum;
			$gameevent->eventTimeOffset += $timeSub;
			$gameevent->Update();
		}
		/*else
		{
			if ($timerevent == null) return;
			$timeAfterSum = $timerevent->duration + $timeSub;
			if ($timeAfterSum < 0)	$timeAfterSum = 0;
			$timerevent->duration = $timeAfterSum;
			$timerevent->Update();
		}*/
		
	}

	public function PlayEventSound_Start($timerevent)
	{
		if ($timerevent->soundStart > 0)
		{
			$soundFile = $this->StadiumControl->GetSoundFile(
				$timerevent->soundStart
			);
			if ($timerevent->type == $this->StadiumControl->PenaltyEventType)
			{
				// penalty - play team name and then Penalty sound
				$this->StadiumControl->SoundPlayFile(
					$soundFile->GetName(), $this->SoundFilename_PenaltyStart
				);
			}
			else
			{
				$this->StadiumControl->SoundPlayFile($soundFile->GetName());
			}
		}
	}
	public function PlayEventSound_End($timerevent)
	{
		if ($timerevent->soundEnd > 0)
		{
			$soundFile = $this->StadiumControl->GetSoundFile(
				$timerevent->soundEnd
			);
			if ($timerevent->type == $this->StadiumControl->PenaltyEventType)
			{
				// penalty - play team name and then Penalty sound
				$this->StadiumControl->SoundPlayFile(
					$soundFile->GetName(), $this->SoundFilename_PenaltyEnds
				);
			}
			else
			{
				$this->StadiumControl->SoundPlayFile($soundFile->GetName());
			}
		}
	}

	public function PlayEventSound_AlmostEnd()
	{
		$event = $this->GetActiveGameTimerEvent();
		if (1)
		{
			$soundFileName = null;
			if ($event->almostEndSound == 0) // load "one minute left" sound
			{
				$soundFileName = $this->SoundFilename_OneMinuteLeft;
			}
			else // load custom sound
			{
				$soundFile = $this->StadiumControl->GetSoundFile(
					$event->almostEndSound);
				$soundFileName = $soundFile->GetName();
			};
			$this->StadiumControl->SoundPlayFile($soundFileName);
		}
	}

	public function GetCountdown()
	{
		$gameEvents = $this->GetActiveGameEvents();
		$game = $this->GetActiveGame();
		$this->Tick();
		$countdown = new Countdown();
		$countdown->team1name = 
			$this->StadiumControl->GetTeam($game->team1)->GetName();
		$countdown->team2name = 
			$this->StadiumControl->GetTeam($game->team2)->GetName();
		$countdown->team1score = 
			$this->StadiumControl->GetTeamNumberOfGoalsInGame($game->id, $game->team1);
		$countdown->team2score = 
			$this->StadiumControl->GetTeamNumberOfGoalsInGame($game->id, $game->team2);
		$countdown->timerActive = intval($this->IsTimerRunning());
		foreach ($gameEvents as $gameEvent) 
		{
			$timerEvent = $this->StadiumControl->GetTimerevent($gameEvent->event);
			$countdownEvent = new CountdownEvent($gameEvent->event);
			$countdownEvent->paralelEvent = $gameEvent->paralelEvent;
			$countdownEvent->lastRecordedTime = $gameEvent->lastRecordedTime;
			$countdownEvent->startTimestamp = $gameEvent->eventTimeStart;
			$countdownEvent->endTimestamp = $gameEvent->eventTimeStop;
			$countdownEvent->timestampOffset = $gameEvent->eventTimeOffset;
			$countdownEvent->duration = $timerEvent->duration;
			$countdownEvent->type = $timerEvent->type;
			$countdownEvent->periodNumber = $timerEvent->periodNumber;
			$countdownEvent->name = $timerEvent->name;

			if ($countdownEvent->startTimestamp == 0)
			{
				$countdownEvent->remainingTime = $countdownEvent->duration;
			}
			else
			{
				$countdownEvent->remainingTime = 
					$countdownEvent->endTimestamp - $countdownEvent->lastRecordedTime;
			}

			$countdown->countdownEvents[] = $countdownEvent;
		}
		return $countdown;
	}
}
?>