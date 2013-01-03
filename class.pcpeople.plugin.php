<?php if (!defined('APPLICATION')) exit();

$PluginInfo['PCpeople'] = array(
	'Name' => 'PCpeople',
	'Description' => 'Allows users to tag postings as "politically incorrect", asking the author to mind their wording. Sounds crazy? Hell yeah! But it works just like the well-known "Thanks" buttons, except conveying a slightly different message. In fact, this plugin is based on the "Thankful people" plugin by Jerl Linandri. It shares a version number with the Thankful People release it is based on.',
	'Version' => '2.14.2.0.18',
	'Date' => '20120103',
	'Author' => 'Victor Hahn',
	'AuthorUrl' => 'http://www.victor-hahn.de',
	'RequiredApplications' => array('Vanilla' => '>=2.0.18'),
	'RequiredTheme' => False, 
	'RequiredPlugins' => False,
	'License' => 'X.Net License'
);

// TODO: PERMISSION THANK FOR CATEGORY
// TODO: AttachMessageThankCount

class PCpeoplePlugin extends Gdn_Plugin {
	
	protected $ThankForComment = array(); // UserIDs array
	protected $CommentGroup = array();
	protected $DiscussionData = array();
	private $Session;

	public function __construct() {
		$this->Session = Gdn::Session();
	}
	
/*  public function DiscussionController_AfterCommentMeta_Handler(&$Sender) {
		$this->AttachMessageThankCount($Sender);
	}
	
	protected function AttachMessageThankCount($Sender) {
		$ThankCount = mt_rand(1, 33);
		echo '<div class="WordingCriticizedBy">'.Plural($Posts, 'Wording criticized by: %s', 'Wording criticized by: %s')), number_format($ThankCount, 0)).'</div>';
	}
	*/
	
	public function PluginController_UnPCtag_Create($Sender) {
		$SessionUserID = GetValue('UserID', Gdn::Session());
		if ($SessionUserID > 0 && C('Plugins.PCpeople.AllowTakeBack', False)) {
			$ThanksLogModel = new PClogModel();
			$Type = GetValue(0, $Sender->RequestArgs);
			$ObjectID = GetValue(1, $Sender->RequestArgs);
			$ThanksLogModel->RemoveThank($Type, $ObjectID, $SessionUserID);
			if ($Sender->DeliveryType() == DELIVERY_TYPE_ALL) {
				$Target = GetIncomingValue('Target', 'discussions');
				Redirect($Target);
			}
			$ThankfulPeopleDataSet = $ThanksLogModel->GetThankfulPeople($Type, $ObjectID);
			$Sender->SetData('NewPCByBox', self::ThankedByBox($ThankfulPeopleDataSet->Result(), False));
			$Sender->Render();
		}
	}
	
	public function PluginController_PCtag_Create($Sender) {
		$Session = $this->Session;
		if (!$Session->IsValid()) return;
		//$Sender->Permission('Plugins.ThankfulPeople.Thank'); // TODO: PERMISSION THANK FOR CATEGORY
		$ThanksLogModel = new PClogModel();
		$Type = GetValue(0, $Sender->RequestArgs);
		$ObjectID = GetValue(1, $Sender->RequestArgs);
		$Field = $ThanksLogModel->GetPrimaryKeyField($Type);
		$UserID = $ThanksLogModel->GetObjectInserUserID($Type, $ObjectID);
		//Check Transient key to prevent CSRF
		$TransKey=GetValue(4, $Sender->RequestArgs);
		if ($TransKey!=urlencode(Gdn::Session()->TransientKey()).($Target ? '&Target='.urlencode($Target) : ''))throw new Exception('Invalid TransKey!');
		if ($UserID == False) throw new Exception('Object has no owner.');
		if ($UserID == $Session->UserID) throw new Exception('You cannot thank yourself.');
		if (!self::IsThankable($Type)) throw new Exception("Not thankable ($Type).");
		
		// Make sure that user is not trying to say thanks twice.
		$Count = $ThanksLogModel->GetCount(array($Field => $ObjectID, 'InsertUserID' => $Session->User->UserID));
		if ($Count < 1) $ThanksLogModel->PutThank($Type, $ObjectID, $UserID);
		
		if ($Sender->DeliveryType() == DELIVERY_TYPE_ALL) {
			$Target = GetIncomingValue('Target', 'discussions');
			Redirect($Target);
		}
		
		$ThankfulPeopleDataSet = $ThanksLogModel->GetThankfulPeople($Type, $ObjectID);
		$Sender->SetData('NewPCByBox', self::ThankedByBox($ThankfulPeopleDataSet->Result(), False));
		$Sender->Render();
	}
	
	public function DiscussionController_Render_Before($Sender) {
		if (!($Sender->DeliveryType() == DELIVERY_TYPE_ALL && $Sender->SyndicationMethod == SYNDICATION_NONE)) return;
		$ThanksLogModel = new PClogModel();
		$DiscussionID = $Sender->DiscussionID;
		// TODO: Permission view thanked
		$CommentIDs = ConsolidateArrayValuesByKey($Sender->CommentData->Result(), 'CommentID');
		$DiscussionCommentThankDataSet = $ThanksLogModel->GetDiscussionComments($DiscussionID, $CommentIDs);
		
		// TODO: FireEvent here to allow collect thanks from other objects
		
		// Consolidate.
		foreach ($DiscussionCommentThankDataSet as $ThankData) {
			$CommentID = $ThankData->CommentID;
			if ($CommentID > 0) {
				$this->CommentGroup[$CommentID][] = $ThankData;
				$this->ThankForComment[$CommentID][] = $ThankData->UserID;
			} elseif ($ThankData->DiscussionID > 0) {
				$this->DiscussionData[$ThankData->UserID] = $ThankData;
			}
		}
		
		$Sender->AddJsFile('jquery.expander.js');
		$Sender->AddCssFile('plugins/PCpeople/design/pcpeople.css');
		$Sender->AddJsFile('plugins/PCpeople/js/pcpeople.functions.js');
		
		$Sender->AddDefinition('ExpandPCList', T('ExpandPCList'));
		$Sender->AddDefinition('CollapsePCList', T('CollapsePCList'));
	}
	
	public static function IsThankable($Type) {
		static $ThankOnly, $ThankDisabled;
		$Type = strtolower($Type);
		if (is_null($ThankOnly)) $ThankOnly = C('Plugins.PCpeople.Only');
		if (is_array($ThankOnly)) {
			if (!in_array($Type, $ThankOnly)) return False;
		}
		if (is_null($ThankDisabled)) $ThankDisabled = C('Plugins.PCpeople.Disabled');
		if (is_array($ThankDisabled)) {
			if (in_array($Type, $ThankDisabled)) return False;
		}
		return True;
	}
	
	public function DiscussionController_CommentOptions_Handler($Sender) {
		$EventArguments =& $Sender->EventArguments;
		$Type = $EventArguments['Type'];
		$Object = $EventArguments['Object'];
		//$Session = Gdn::Session();
		$SessionUserID = $this->Session->UserID;
		if ($SessionUserID <= 0 || $Object->InsertUserID == $SessionUserID) return;
		
		if (!self::IsThankable($Type)) return;
		
		static $AllowTakeBack;
		if (is_null($AllowTakeBack)) $AllowTakeBack = C('Plugins.PCpeople.AllowTakeBack', False);
		$AllowThank = True;
		
		switch ($Type) {
			case 'Discussion': {
				$DiscussionID = $ObjectID = $Object->DiscussionID;
				if (array_key_exists($SessionUserID, $this->DiscussionData)) $AllowThank = False;
				break;
			}
			case 'Comment': {
				$CommentID = $ObjectID = $Object->CommentID;
				if (array_key_exists($CommentID, $this->ThankForComment) && in_array($SessionUserID, $this->ThankForComment[$CommentID])) $AllowThank = False;
				break;
			}
		}
		
	
		if ($AllowThank) {
			static $LocalizedThankButtonText;
			if ($LocalizedThankButtonText === Null) $LocalizedThankButtonText = T('PCcommentOption', T('Mind your wording'));
			//$ThankUrl = 'plugin/PCtag/'.strtolower($Type).'/'.$ObjectID.'?Target='.$Sender->SelfUrl;
			//Append the Transientkey at the end of the url to prevent CSRF
			$ThankUrl = 'plugin/PCtag/'.strtolower($Type).'/'.$ObjectID.'?Target='.$Sender->SelfUrl.'/'.urlencode(Gdn::Session()->TransientKey()).($Target ? '&Target='.urlencode($Target) : '').'?';
			$Option = '<span class="PCtag">'.Anchor($LocalizedThankButtonText, $ThankUrl).'</span>';
			$Sender->Options .= $Option;
		} elseif ($AllowTakeBack) {
			// Allow unthank
			static $LocalizedUnThankButtonText;
			if (is_null($LocalizedUnThankButtonText)) $LocalizedUnThankButtonText = T('UnPCcommentOption', T('Take back wording remark'));
			$UnThankUrl = 'plugin/unPCtag/'.strtolower($Type).'/'.$ObjectID.'?Target='.$Sender->SelfUrl;
			$Option = '<span class="UnPCtag">'.Anchor($LocalizedUnThankButtonText, $UnThankUrl).'</span>';
			$Sender->Options .= $Option;
		}
	}
	
	public function DiscussionController_AfterCommentBody_Handler($Sender) {
		$Object = $Sender->EventArguments['Object'];
		$Type = $Sender->EventArguments['Type'];
		$ThankedByBox = False;
		switch ($Type) {
			case 'Comment': {
				$ThankedByCollection =& $this->CommentGroup[$Object->CommentID];
				if ($ThankedByCollection) $ThankedByBox = self::ThankedByBox($ThankedByCollection);
				break;
			}
			case 'Discussion': {
				if (count($this->DiscussionData) > 0) $ThankedByBox = self::ThankedByBox($this->DiscussionData);
				break;
			}
			default: throw new Exception('What...');
		}
		if ($ThankedByBox !== False) echo $ThankedByBox;
	}
	
	public static function ThankedByBox($Collection, $Wrap = True) {
		$List = implode(' ', array_map('UserAnchor', $Collection));
		$ThankCount = count($Collection);
		//$ThankCountHtml = Wrap($ThankCount);
		$LocalizedPluralText = Plural($ThankCount, 'Wording criticized by %1$s', 'Wording criticized by %1$s');
		$Html = '<span class="PCBy">'.$LocalizedPluralText.'</span>'.$List;
		if ($Wrap) $Html = Wrap($Html, 'div', array('class' => 'PCByBox'));
		return $Html;
	}
	
	public function UserInfoModule_OnBasicInfo_Handler($Sender) {
		echo Wrap(T('UserInfoModule.PCremarks'), 'dt', array('class' => 'ReceivedPCcount'));
		echo Wrap($Sender->User->ReceivedPCcount, 'dd', array('class' => 'ReceivedPCcount'));
	}
	
	public function ProfileController_Render_Before($Sender) {
		if (!($Sender->DeliveryType() == DELIVERY_TYPE_ALL && $Sender->SyndicationMethod == SYNDICATION_NONE)) return;
		$Sender->AddCssFile('plugins/PCpeople/design/pcpeople.css');
	}
	
	public function ProfileController_AddProfileTabs_Handler($Sender) {
		$ReceivedThankCount = GetValue('ReceivedPCcount', $Sender->User);
		if ($ReceivedThankCount > 0) {
			$UserReference = ArrayValue(0, $Sender->RequestArgs, '');
			$Username = ArrayValue(1, $Sender->RequestArgs, '');
			$Thanked = T('Profile.Tab.PCremarks', T('Wording remarks received')).'<span>'.$ReceivedThankCount.'</span>';
			$Sender->AddProfileTab($Thanked, 'profile/receivedPC/'.$UserReference.'/'.$Username, 'Wording remarks received');
		}
	}
	
	public function ProfileController_ReceivedPC_Create($Sender) {
		$UserReference = ArrayValue(0, $Sender->RequestArgs, '');
		$Username = ArrayValue(1, $Sender->RequestArgs, '');
		$Sender->GetUserInfo($UserReference, $Username);
		$ViewingUserID = $Sender->User->UserID;
		
		$ReceivedThankCount = $Sender->User->ReceivedThankCount;
		$Thanked = T('Profile.Tab.PCremarks', T('Wording remarks received')).'<span>'.$ReceivedPCcount.'</span>';
		$View = $this->GetView('receivedthanks.php');
		$Sender->SetTabView($Thanked, $View);
		$ThanksLogModel = new PClogModel();
		// TODO: PAGINATION
		list($Sender->ThankData, $Sender->ThankObjects) = $ThanksLogModel->GetReceivedThanks(array('t.UserID' => $ViewingUserID), 0, 50);
		$Sender->Render();
	}
	
	public function Tick_Every_720_Hours_Handler($Sender) {
		PClogModel::CleanUp();
		PClogModel::RecalculateUserReceivedThankCount();
	}
	
	public function Structure() {
/*		Gdn::Structure()
			->Table('Comment')
			->Column('PCcount', 'usmallint', 0)
			->Set();
		
		Gdn::Structure()
			->Table('Discussion')
			->Column('PCcount', 'usmallint', 0)
			->Set();*/
		Gdn::Structure()
			->Table('User')
			//->Column('PCcount', 'usmallint', 0)
			->Column('ReceivedPCcount', 'usmallint', 0)
			->Set();
		
		Gdn::Structure()
			->Table('PClog')
			->Column('UserID', 'umediumint', False, 'key')
			->Column('CommentID', 'umediumint', 0)
			->Column('DiscussionID', 'umediumint', 0)
			->Column('DateInserted', 'datetime')
			->Column('InsertUserID', 'umediumint', False, 'key')
			->Engine('MyISAM')
			->Set();
			
		$RequestArgs = Gdn::Controller()->RequestArgs;
		if (ArrayHasValue($RequestArgs, 'vanilla')) {
			PClogModel::RecalculateUserReceivedThankCount();
		}
		
		//PClogModel::RecalculateCommentThankCount();
		//PClogModel::RecalculateDiscussionThankCount();
	}
		
	public function Setup() {
		$this->Structure();
	}
}
