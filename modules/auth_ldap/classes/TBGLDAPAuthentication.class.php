<?php

	/**
	 * LDAP Authentication
	 *
	 * @author
	 * @version 0.1
	 * @license http://www.opensource.org/licenses/mozilla1.1.php Mozilla Public License 1.1 (MPL 1.1)
	 * @package auth_ldap
	 * @subpackage core
	 */

	/**
	 * LDAP Authentication
	 *
	 * @package auth_ldap
	 * @subpackage core
	 */
	class TBGLDAPAuthentication extends TBGModule
	{

		protected $_longname = 'LDAP Authentication';
		
		protected $_description = 'Allows authentication against a LDAP or Active Directory server';
		
		protected $_module_config_title = 'LDAP Authentication';
		
		protected $_module_config_description = 'Configure server connection settings';
		
		protected $_module_version = '1.0';
		
		protected $_has_config_settings = true;

		/**
		 * Return an instance of this module
		 *
		 * @return LDAP Authentication
		 */
		public static function getModule()
		{
			return TBGContext::getModule('auth_ldap');
		}

		protected function _initialize()
		{
		}
		
		protected function _addRoutes()
		{
			$this->addRoute('ldap_test', '/test/ldap', 'testConnection');
			$this->addRoute('ldap_prune', '/configure/module/auth_ldap/prune', 'pruneUsers');
			$this->addRoute('ldap_import', '/configure/module/auth_ldap/import', 'importUsers');
		}

		protected function _install($scope)
		{
		}

		protected function _uninstall()
		{
		}
		
		public final function getType()
		{
			return parent::MODULE_AUTH;
		}

		public function getRoute()
		{
			return TBGContext::getRouting()->generate('ldap_authentication_index');
		}

		public function postConfigSettings(TBGRequest $request)
		{
			$settings = array('hostname', 'u_type', 'g_type', 'b_dn', 'groups', 'u_attr', 'g_attr', 'e_attr', 'f_attr', 'g_dn', 'control_user', 'control_pass');
			foreach ($settings as $setting)
			{
				if (($setting == 'u_type' || $setting == 'g_type') && $request->getParameter($setting) == '')
				{
					if ($setting == 'u_type')
					{
						$this->saveSetting($setting, 'person');
					}
					else
					{
						$this->saveSetting($setting, 'group');
					}
				}
				else
				{
					if ($request->hasParameter($setting))
					{
						$this->saveSetting($setting, $request->getParameter($setting));
					}
				}
			}
		}
		
		public function connect()
		{
			$host = $this->getSetting('hostname');
			$failed = false;

			$connection = ldap_connect($host);
			ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
			
			if ($connection == false): $failed = true; endif;

			if ($failed)
			{
				throw new Exception(TBGContext::geti18n()->__('Failed to connect to server'));
			}
			
			return $connection;
		}
		
		public function bind($connection, $lduser = null, $ldpass = null)
		{
			if ($ldpass == '' && $lduser == '')
			{
				$bind = ldap_bind($connection);
			}
			else
			{
				$bind = ldap_bind($connection, $lduser, $ldpass);
			}
			
			if (!$bind)
			{
				ldap_unbind($connection);
				TBGLogging::log('bind failed: '.ldap_error($connection), 'ldap', TBGLogging::LEVEL_FATAL);
				throw new Exception(TBGContext::geti18n()->__('Failed to bind: ').ldap_error($connection));
			}
		}
		
		public function escape($string)
		{
			$chars = array('\\', '*', '()', ')', chr(0));
			foreach ($chars as $char)
			{
				$string = str_replace($char, '\\'.$char, $string);
			}

			return $string;
		}

		public function doLogin($username, $password, $mode = 1)
		{	
			$validgroups = $this->getSetting('groups');
			$base_dn = $this->getSetting('b_dn');
			$username_attr = $this->escape($this->getSetting('u_attr'));
			$fullname_attr = $this->escape($this->getSetting('f_attr'));
			$email_attr = $this->escape($this->getSetting('e_attr'));
			$groups_members_attr = $this->escape($this->getSetting('g_attr'));
			
			$user_class = TBGContext::getModule('auth_ldap')->getSetting('u_type');
			$group_class = TBGContext::getModule('auth_ldap')->getSetting('g_type');
			
			$email = null;
			
			/*
			 * Do the LDAP check here.
			 * 
			 * If a connection error or something, throw an exception and log
			 * 
			 * If we can, set $mail and $realname to correct values from LDAP
			 * otherwise don't touch those variables.
			 * 
			 * To log do:
			 * TBGLogging::log('error goes here', 'ldap', TBGLogging::LEVEL_FATAL);
			 */
			try
			{
				/*
				 * First job is to connect to our control user (may be an anonymous bind)
				 * so we can find the user we want to log in as/validate.
				 */
				$connection = $this->connect();
				
				$control_user = $this->getSetting('control_user');
				$control_password = $this->getSetting('control_pass');

				$this->bind($control_user, $control_password, $connection);
				
				// Assume bind successful, otherwise we would have had an exception
				
				/*
				 * Search for a user with the username specified. We search in the base_dn, so we can
				 * find users in multiple parts of the directory, and only return users of a specific
				 * class (default person).
				 * 
				 * We want exactly 1 user to be returned. We get the user's full name, email, cn
				 * and dn.
				 */
				$fields = array($fullname_attr, $email_attr, 'cn', 'distinguishedName');
				$filter = '(&(objectClass='.TBGLDAPAuthentication::getModule()->escape($user_class).')('.$username_attr.'='.$this->escape($username2).'))';
				
				$results = ldap_search($connection, $base_dn, $filter, $fields);
				
				if (!$results)
				{
					TBGLogging::log('failed to search for user: '.ldap_error($connection), 'ldap', TBGLogging::LEVEL_FATAL);
					throw new Exception(TBGContext::geti18n()->__('Search failed: ').ldap_error($connection));
				}
				
				$data = ldap_get_entries($connection, $results);
				
				// User does not exist
				if ($data['count'] == 0)
				{
					TBGLogging::log('could not find user '.$username.', class '.$user_class.', attribute '.$username_attr, 'ldap', TBGLogging::LEVEL_FATAL);
					throw new Exception(TBGContext::geti18n()->__('User does not exist in the directory'));
				}
				
				// If we have more than 1 user, something is seriously messed up...
				if ($data['count'] > 1)
				{
					TBGLogging::log('too many users for '.$username.', class '.$user_class.', attribute '.$username_attr, 'ldap', TBGLogging::LEVEL_FATAL);
					throw new Exception(TBGContext::geti18n()->__('This user was found multiple times in the directory, please contact your admimistrator'));
				}

				/*
				 * If groups are specified, perform group restriction tests
				 */
				if ($validgroups != '')
				{
					/*
					 * We will repeat this for every group, but groups are supplied as a comma-separated list
					 */
					if (strstr($validgroups, ','))
					{
						$groups = explode(',', $validgroups);
					}
					else
					{
						$groups = array();
						$groups[] = $validgroups;
					}
					
					// Assumed we are initially banned
					$allowed = false;
					
					foreach ($groups as $group)
					{
						// No need to carry on looking if we have access
						if ($allowed == true): continue; endif;
						
						/*
						 * Find the group we are looking for, we search the entire directory as per users (See that stuff)
						 * We want to find 1 group, if we don't get 1, silently ignore this group.
						 */
						$fields2 = array($groups_members_attr);
						$filter2 = '(&(objectClass='.TBGLDAPAuthentication::getModule()->escape($group_class).')(cn='.$this->escape($group).'))';
						
						$results2 = ldap_search($connection, $base_dn, $filter2, $fields2);
						
						if (!$results2)
						{
							TBGLogging::log('failed to search for user after binding: '.ldap_error($connection), 'ldap', TBGLogging::LEVEL_FATAL);
							throw new Exception(TBGContext::geti18n()->__('Search failed ').ldap_error($connection));
						}
						
						$data2 = ldap_get_entries($connection, $results2);
						
						if ($data2['count'] != 1)
						{
							continue;
						}
						
						/*
						 * Look through the group's member list. If we are found, grant access.
						 */
						foreach ($data2[0][strtolower($groups_members_attr)] as $member)
						{
							if ($member == $data[0]['distinguishedname'][0])
							{
								$allowed = true;
							}
						}
					}
					
					if ($allowed == false)
					{
						throw new Exception(TBGContext::getI18n()->__('You are not a member of a group allowed to log in'));
					}
				}

				/*
				 * Set user's properties.
				 * Realname is obtained from directory, if not found we set it to the username
				 * Email is obtained from directory, if not found we set it to blank
				 */
				if (!array_key_exists(strtolower($fullname_attr), $data[0]))
				{
					$realname = $username;
				}
				else
				{
					$realname = $data[0][strtolower($fullname_attr)][0];
				}
				
				if (!array_key_exists(strtolower($email_attr), $data[0]))
				{
					$email = '';
				}
				else
				{
					$email = $data[0][strtolower($email_attr)][0];
				}
				
				/*
				 * If we are performing a login, now bind to the user and see if the credentials
				 * are valid. We bind using the full DN of the user, so no need for DOMAIN\ stuff
				 * on Windows, and more importantly it fixes other servers.
				 * 
				 * If the bind fails (exception), we throw a nicer exception and don't continue.
				 */
				if ($mode == 1)
				{
					try
					{
						$bind = $this->bind($connection, $data[0]['distinguishedname'][0], $password);
					}
					catch (Exception $e)
					{
						throw new Exception(TBGContext::geti18n()->__('Your password was not accepted by the server'));
					}
				}
			}
			catch (Exception $e)
			{
				ldap_unbind($connection);
				throw $e;
			}
			
			try
			{
				/*
				 * Get the user object. If the user exists, update the user's
				 * data from the directory.
				 */
				$user = TBGUser::getByUsername($username);
				if ($user instanceof TBGUser)
				{
					$user->setBuddyname($realname);
					$user->setRealname($realname);
					$user->setPassword($user->getJoinedDate().$username); // update password
					$user->setEmail($email); // update email address
					$user->save();
				}
				else
				{
					/*
					 * If not, and we are performing an initial login, create the user object
					 * if we are validating a log in, kick the user out as the session is invalid.
					 */
					if ($mode == 1)
					{
						// create user
						$user = new TBGUser();
						$user->setUsername($username);
						$user->setRealname('temporary');
						$user->setBuddyname($username);
						$user->setEmail('temporary');
						$user->setEnabled();
						$user->setActivated();
						$user->setPassword($user->getJoinedDate().$username);
						$user->setJoined();
						$user->save();
					}
					else
					{
						throw new Exception('User does not exist in TBG');
					}
				}
			}
			catch (Exception $e)
			{
				ldap_unbind($connection);
				throw $e;
			}

			ldap_unbind($connection);
			
			/*
			 * Set cookies and return user row for general operations.
			 */
			TBGContext::getResponse()->setCookie('tbg3_username', $username);
			TBGContext::getResponse()->setCookie('tbg3_password', TBGUser::hashPassword($user->getJoinedDate().$username));

			return TBGUsersTable::getTable()->getByUsername($username);
		}

		public function verifyLogin($username)
		{
			return $this->doLogin($username, 'a', 2);
		}
	}

