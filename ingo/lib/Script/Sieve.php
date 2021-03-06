<?php
/**
 * Copyright 2003-2014 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (ASL).  If you
 * did not receive this file, see http://www.horde.org/licenses/apache.
 *
 * @author   Mike Cochrane <mike@graftonhall.co.nz>
 * @author   Jan Schneider <jan@horde.org>
 * @category Horde
 * @license  http://www.horde.org/licenses/apache ASL
 * @package  Ingo
 */

/**
 * The Ingo_Script_Sieve class represents a Sieve Script.
 *
 * @author   Mike Cochrane <mike@graftonhall.co.nz>
 * @author   Jan Schneider <jan@horde.org>
 * @category Horde
 * @license  http://www.horde.org/licenses/apache ASL
 * @package  Ingo
 */
class Ingo_Script_Sieve extends Ingo_Script_Base
{
    /**
     * A list of driver features.
     *
     * @var array
     */
    protected $_features = array(
        /* Can tests be case sensitive? */
        'case_sensitive' => true,
        /* Does the driver support setting IMAP flags? */
        'imap_flags' => true,
        /* Does the driver support the stop-script option? */
        'stop_script' => true,
        /* Can this driver perform on demand filtering? */
        'on_demand' => false,
        /* Does the driver require a script file to be generated? */
        'script_file' => true,
    );

    /**
     * The list of actions allowed (implemented) for this driver.
     *
     * @var array
     */
    protected $_actions = array(
        Ingo_Storage::ACTION_KEEP,
        Ingo_Storage::ACTION_MOVE,
        Ingo_Storage::ACTION_DISCARD,
        Ingo_Storage::ACTION_REDIRECT,
        Ingo_Storage::ACTION_REDIRECTKEEP,
        Ingo_Storage::ACTION_MOVEKEEP,
        Ingo_Storage::ACTION_REJECT,
        Ingo_Storage::ACTION_FLAGONLY,
        Ingo_Storage::ACTION_NOTIFY
    );

    /**
     * The categories of filtering allowed.
     *
     * @var array
     */
    protected $_categories = array(
        Ingo_Storage::ACTION_BLACKLIST,
        Ingo_Storage::ACTION_WHITELIST,
        Ingo_Storage::ACTION_VACATION,
        Ingo_Storage::ACTION_FORWARD,
        Ingo_Storage::ACTION_SPAM
    );

    /**
     * The list of tests allowed (implemented) for this driver.
     *
     * @var array
     */
    protected $_tests = array(
        'contains',
        'not contain',
        'is',
        'not is',
        'begins with',
        'not begins with',
        'ends with',
        'not ends with',
        'exists',
        'not exist',
        'less than',
        'less than or equal to',
        'equal',
        'not equal',
        'greater than',
        'greater than or equal to',
        'regex',
        'matches',
        'not matches'
    );

    /**
     * The types of tests allowed (implemented) for this driver.
     *
     * @var array
     */
    protected $_types = array(
        Ingo_Storage::TYPE_HEADER,
        Ingo_Storage::TYPE_SIZE,
        Ingo_Storage::TYPE_BODY
    );

    /**
     * The blocks that have to appear at the end of the code.
     *
     * @var array
     */
    protected $_endBlocks = array();

    /**
     * Escape a string according to Sieve RFC 3028 [2.4.2].
     *
     * @param string $string      The string to escape.
     * @param boolean $regexmode  Is the escaped string a regex value?
     *                            Defaults to no.
     *
     * @return string  The escaped string.
     */
    static public function escapeString($string, $regexmode = false)
    {
        /* Remove any backslashes in front of commas. */
        $string = str_replace('\,', ',', $string);

        return $regexmode
            ? str_replace('"', addslashes('"'), $string)
            : str_replace(array('\\', '"'), array(addslashes('\\'), addslashes('"')), $string);
    }

    /**
     * Checks if all rules are valid.
     *
     * @return boolean|string  True if all rules are valid, an error message
     *                         otherwise.
     */
    public function check()
    {
        foreach ($this->_recipes as $block) {
            $res = $block['object']->check();
            if ($res !== true) {
                return $res;
            }
        }

        return true;
    }

    /**
     * Adds all blocks necessary for the forward rule.
     */
    protected function _addForwardBlocks()
    {
        if (!$this->_validRule(Ingo_Storage::ACTION_FORWARD)) {
            return;
        }

        $forward = $this->_params['storage']
             ->retrieve(Ingo_Storage::ACTION_FORWARD);
        $fwd_addr = $forward->getForwardAddresses();
        if (empty($fwd_addr)) {
            return;
        }

        $action = array();
        foreach ($fwd_addr as $addr) {
            $addr = trim($addr);
            if (!empty($addr)) {
                $action[] = new Ingo_Script_Sieve_Action_Redirect(array('address' => $addr));
            }
        }

        if (count($action)) {
            if($forward->getForwardKeep()) {
                $this->_endBlocks[] = new Ingo_Script_Sieve_Comment(_("Forward Keep Action"));
                $if = new Ingo_Script_Sieve_If(new Ingo_Script_Sieve_Test_True());
                $if->setActions(array(new Ingo_Script_Sieve_Action_Keep(),
                                      new Ingo_Script_Sieve_Action_Stop()));
                $this->_endBlocks[] = $if;
            } else {
                $action[] = new Ingo_Script_Sieve_Action_Stop();
            }
        }

        $this->_addItem(Ingo::RULE_FORWARD, new Ingo_Script_Sieve_Comment(_("Forwards")));

        $test = new Ingo_Script_Sieve_Test_True();
        $if = new Ingo_Script_Sieve_If($test);
        $if->setActions($action);
        $this->_addItem(Ingo::RULE_FORWARD, $if);
    }

    /**
     * Adds all blocks necessary for the blacklist rule.
     */
    protected function _addBlacklistBlocks()
    {
        if (!$this->_validRule(Ingo_Storage::ACTION_BLACKLIST)) {
            return;
        }

        $blacklist = $this->_params['storage']
             ->retrieve(Ingo_Storage::ACTION_BLACKLIST);
        $bl_addr = $blacklist->getBlacklist();
        $folder = $blacklist->getBlacklistFolder();
        if (empty($bl_addr)) {
            return;
        }

        $action = array();
        if (empty($folder)) {
            $action[] = new Ingo_Script_Sieve_Action_Discard();
        } elseif ($folder == Ingo::BLACKLIST_MARKER) {
            $action[] = new Ingo_Script_Sieve_Action_Addflag(array(
                'flags' => Ingo_Storage::FLAG_DELETED,
                'imapflags' => !empty($this->_params['imapflags'])
            ));
            $action[] = new Ingo_Script_Sieve_Action_Keep();
            $action[] = new Ingo_Script_Sieve_Action_Removeflag(array(
                'flags' => Ingo_Storage::FLAG_DELETED,
                'imapflags' => !empty($this->_params['imapflags'])
            ));
        } else {
            $action[] = new Ingo_Script_Sieve_Action_Fileinto(array_merge($this->_params, array('folder' => $folder)));
        }

        $action[] = new Ingo_Script_Sieve_Action_Stop();

        $this->_addItem(Ingo::RULE_BLACKLIST, new Ingo_Script_Sieve_Comment(_("Blacklisted Addresses")));

        /* Split the test up to only do 5 addresses at a time. */
        $temp = array();
        $wildcards = array();
        foreach ($bl_addr as $address) {
            if (!empty($address)) {
                if ((strstr($address, '*') !== false) ||
                    (strstr($address, '?') !== false)) {
                    $wildcards[] = $address;
                } else {
                    $temp[] = $address;
                }
            }
            if (count($temp) == 5) {
                $test = new Ingo_Script_Sieve_Test_Address(array('headers' => "From\nSender\nResent-From", 'addresses' => implode("\n", $temp)));
                $if = new Ingo_Script_Sieve_If($test);
                $if->setActions($action);
                $this->_addItem(Ingo::RULE_BLACKLIST, $if);
                $temp = array();
            }
            if (count($wildcards) == 5) {
                $test = new Ingo_Script_Sieve_Test_Address(array('headers' => "From\nSender\nResent-From", 'match-type' => ':matches', 'addresses' => implode("\n", $wildcards)));
                $if = new Ingo_Script_Sieve_If($test);
                $if->setActions($action);
                $this->_addItem(Ingo::RULE_BLACKLIST, $if);
                $wildcards = array();
            }
        }

        if ($temp) {
            $test = new Ingo_Script_Sieve_Test_Address(array('headers' => "From\nSender\nResent-From", 'addresses' => implode("\n", $temp)));
            $if = new Ingo_Script_Sieve_If($test);
            $if->setActions($action);
            $this->_addItem(Ingo::RULE_BLACKLIST, $if);
        }

        if ($wildcards) {
            $test = new Ingo_Script_Sieve_Test_Address(array('headers' => "From\nSender\nResent-From", 'match-type' => ':matches', 'addresses' => implode("\n", $wildcards)));
            $if = new Ingo_Script_Sieve_If($test);
            $if->setActions($action);
            $this->_addItem(Ingo::RULE_BLACKLIST, $if);
        }
    }

    /**
     * Adds all blocks necessary for the whitelist rule.
     */
    protected function _addWhitelistBlocks()
    {
        if (!$this->_validRule(Ingo_Storage::ACTION_WHITELIST)) {
            return;
        }

        $whitelist = $this->_params['storage']
             ->retrieve(Ingo_Storage::ACTION_WHITELIST);
        $wl_addr = $whitelist->getWhitelist();
        if (empty($wl_addr)) {
            return;
        }

        $this->_addItem(Ingo::RULE_WHITELIST, new Ingo_Script_Sieve_Comment(_("Whitelisted Addresses")));

        $action = array(new Ingo_Script_Sieve_Action_Keep(), new Ingo_Script_Sieve_Action_Stop());
        $test = new Ingo_Script_Sieve_Test_Address(array('headers' => "From\nSender\nResent-From", 'addresses' => implode("\n", $wl_addr)));
        $if = new Ingo_Script_Sieve_If($test);
        $if->setActions($action);
        $this->_addItem(Ingo::RULE_WHITELIST, $if);
    }

    /**
     * Adds all blocks necessary for the vacation rule.
     */
    protected function _addVacationBlocks()
    {
        if (!$this->_validRule(Ingo_Storage::ACTION_VACATION)) {
            return;
        }

        $vacation = $this->_params['storage']
             ->retrieve(Ingo_Storage::ACTION_VACATION);
        $vacation_addr = $vacation->getVacationAddresses();
        if (!count($vacation_addr)) {
            return;
        }

        $vals = array(
            'subject' => $vacation->getVacationSubject(),
            'days' => $vacation->getVacationDays(),
            'addresses' => $vacation_addr,
            'start' => $vacation->getVacationStart(),
            'start_year' => $vacation->getVacationStartYear(),
            'start_month' => $vacation->getVacationStartMonth(),
            'start_day' => $vacation->getVacationStartDay(),
            'end' => $vacation->getVacationEnd(),
            'end_year' => $vacation->getVacationEndYear(),
            'end_month' => $vacation->getVacationEndMonth(),
            'end_day' => $vacation->getVacationEndDay(),
            'reason' => $vacation->getVacationReason(),
        );

        $action = $tests = array();
        $action[] = new Ingo_Script_Sieve_Action_Vacation($vals);

        if ($vacation->getVacationIgnorelist()) {
            $mime_headers = new Horde_Mime_Headers();
            $headers = $mime_headers->listHeaders();
            $headers['Mailing-List'] = null;
            foreach (array_keys($headers) as $h) {
                $tests[] = new Ingo_Script_Sieve_Test_Not(
                    new Ingo_Script_Sieve_Test_Exists(array('headers' => $h))
                );
            }
            $vals = array('headers' => 'Precedence',
                          'match-type' => ':is',
                          'strings' => "list\nbulk\njunk",
                          'comparator' => 'i;ascii-casemap');
            $tmp = new Ingo_Script_Sieve_Test_Header($vals);
            $tests[] = new Ingo_Script_Sieve_Test_Not($tmp);
            $vals = array('headers' => 'To',
                          'match-type' => ':matches',
                          'strings' => 'Multiple recipients of*',
                          'comparator' => 'i;ascii-casemap');
            $tmp = new Ingo_Script_Sieve_Test_Header($vals);
            $tests[] = new Ingo_Script_Sieve_Test_Not($tmp);
        }

        $addrs = array();
        foreach ($vacation->getVacationExcludes() as $addr) {
            $addr = trim($addr);
            if (!empty($addr)) {
                $addrs[] = $addr;
            }
        }

        if ($addrs) {
            $tmp = new Ingo_Script_Sieve_Test_Address(array('headers' => "From\nSender\nResent-From", 'addresses' => implode("\n", $addrs)));
            $tests[] = new Ingo_Script_Sieve_Test_Not($tmp);
        }

        $this->_addItem(Ingo::RULE_VACATION, new Ingo_Script_Sieve_Comment(_("Vacation")));

        if ($tests) {
            $test = new Ingo_Script_Sieve_Test_Allof($tests);
            $if = new Ingo_Script_Sieve_If($test);
            $if->setActions($action);
            $this->_addItem(Ingo::RULE_VACATION, $if);
        } else {
            $this->_addItem(Ingo::RULE_VACATION, $action[0]);
        }
    }

    /**
     * Adds all blocks necessary for the spam rule.
     */
    protected function _addSpamBlocks()
    {
        if (!$this->_validRule(Ingo_Storage::ACTION_SPAM)) {
            return;
        }

        $spam = $this->_params['storage']
             ->retrieve(Ingo_Storage::ACTION_SPAM);
        if ($spam === false) {
            return;
        }

        $this->_addItem(Ingo::RULE_SPAM, new Ingo_Script_Sieve_Comment(_("Spam Filter")));

        $actions = array();
        $actions[] = new Ingo_Script_Sieve_Action_Fileinto(array_merge($this->_params, array('folder' => $spam->getSpamFolder())));

        if ($this->_params['spam_compare'] == 'numeric') {
            $vals = array(
                'headers' => $this->_params['spam_header'],
                'comparison' => 'ge',
                'value' => $spam->getSpamLevel(),
            );
            $test = new Ingo_Script_Sieve_Test_Relational($vals);
        } elseif ($this->_params['spam_compare'] == 'string') {
            $vals = array(
                'headers' => $this->_params['spam_header'],
                'match-type' => ':contains',
                'strings' => str_repeat($this->_params['spam_char'],
                                        $spam->getSpamLevel()),
                'comparator' => 'i;ascii-casemap',
            );
            $test = new Ingo_Script_Sieve_Test_Header($vals);
        }

        $actions[] = new Ingo_Script_Sieve_Action_Stop();

        $if = new Ingo_Script_Sieve_If($test);
        $if->setActions($actions);
        $this->_addItem(Ingo::RULE_SPAM, $if);
    }

    /**
     * Generates the scripts to do the filtering specified in the rules.
     *
     * @return array  The scripts.
     */
    public function generate()
    {
        if (!$this->_generated) {
            $this->_generate();
            $this->_generated = true;
        }

        /* Build a list of required sieve extensions. */
        $requires = array();
        foreach ($this->_recipes as $item) {
            $rule = isset($this->_params['transport'][$item['rule']])
                ? $item['rule']
                : Ingo::RULE_ALL;
            if (!isset($requires[$rule])) {
                $requires[$rule] = array();
            }
            $requires[$rule] = array_merge($requires[$rule],
                                           $item['object']->requires());
        }
        foreach ($requires as $rule => $require) {
            $this->_insertItem(
                $rule,
                new Ingo_Script_Sieve_Require(array_unique($require)),
                null,
                1
            );
        }

        return parent::generate();
    }

    /**
     * Generates the Sieve script to do the filtering specified in the rules.
     */
    protected function _generate()
    {
        $this->_addItem(Ingo::RULE_ALL, new Ingo_Script_Sieve_Comment(
            "Sieve Filter\n"
            . _("Generated by Ingo") . ' (http://www.horde.org/apps/ingo/) ('
            . trim(strftime($this->_params['date_format'] . ', ' . $this->_params['time_format']))
            . ")"
        ));

        $filters = $this->_params['storage']
             ->retrieve(Ingo_Storage::ACTION_FILTERS);
        foreach ($filters->getFilterList($this->_params['skip']) as $filter) {
            /* Check to make sure this is a valid rule and that the rule
               is not disabled. */
            if (!$this->_validRule($filter['action']) ||
                !empty($filter['disable'])) {
                continue;
            }

            $action = array();
            switch ($filter['action']) {
            case Ingo_Storage::ACTION_KEEP:
                if (!empty($filter['flags'])) {
                    $action[] = new Ingo_Script_Sieve_Action_Addflag(array(
                        'flags' => $filter['flags'],
                        'imapflags' => !empty($this->_params['imapflags'])
                    ));
                }

                $action[] = new Ingo_Script_Sieve_Action_Keep();

                if (!empty($filter['flags'])) {
                    $action[] = new Ingo_Script_Sieve_Action_Removeflag(array(
                        'flags' => $filter['flags'],
                        'imapflags' => !empty($this->_params['imapflags'])
                    ));
                }
                break;

            case Ingo_Storage::ACTION_DISCARD:
                $action[] = new Ingo_Script_Sieve_Action_Discard();
                break;

            case Ingo_Storage::ACTION_MOVE:
                if (!empty($filter['flags'])) {
                    $action[] = new Ingo_Script_Sieve_Action_Addflag(array(
                        'flags' => $filter['flags'],
                        'imapflags' => !empty($this->_params['imapflags'])
                    ));
                }

                $action[] = new Ingo_Script_Sieve_Action_Fileinto(array_merge($this->_params, array('folder' => $filter['action-value'])));

                if (!empty($filter['flags'])) {
                    $action[] = new Ingo_Script_Sieve_Action_Removeflag(array(
                        'flags' => $filter['flags'],
                        'imapflags' => !empty($this->_params['imapflags'])
                    ));
                }
                break;

            case Ingo_Storage::ACTION_REJECT:
                $action[] = new Ingo_Script_Sieve_Action_Reject(array('reason' => $filter['action-value']));
                break;

            case Ingo_Storage::ACTION_REDIRECT:
                $parser = new Horde_Mail_Rfc822();
                foreach($parser->parseAddressList($filter['action-value']) as $address){
                    $action[] = new Ingo_Script_Sieve_Action_Redirect(array('address' => $address));
                }
                break;

            case Ingo_Storage::ACTION_REDIRECTKEEP:
                if (!empty($filter['flags'])) {
                    $action[] = new Ingo_Script_Sieve_Action_Addflag(array(
                        'flags' => $filter['flags'],
                        'imapflags' => !empty($this->_params['imapflags'])
                    ));
                }

                $parser = new Horde_Mail_Rfc822();
                foreach($parser->parseAddressList($filter['action-value']) as $address){
                    $action[] = new Ingo_Script_Sieve_Action_Redirect(array('address' => $address));
                }

                $action[] = new Ingo_Script_Sieve_Action_Keep();

                if (!empty($filter['flags'])) {
                    $action[] = new Ingo_Script_Sieve_Action_Removeflag(array(
                        'flags' => $filter['flags'],
                        'imapflags' => !empty($this->_params['imapflags'])
                    ));
                }
                break;

            case Ingo_Storage::ACTION_MOVEKEEP:
                if (!empty($filter['flags'])) {
                    $action[] = new Ingo_Script_Sieve_Action_Addflag(array(
                        'flags' => $filter['flags'],
                        'imapflags' => !empty($this->_params['imapflags'])
                    ));
                }

                $action[] = new Ingo_Script_Sieve_Action_Keep();
                $action[] = new Ingo_Script_Sieve_Action_Fileinto(array_merge($this->_params, array('folder' => $filter['action-value'])));

                if (!empty($filter['flags'])) {
                    $action[] = new Ingo_Script_Sieve_Action_Removeflag(array(
                        'flags' => $filter['flags'],
                        'imapflags' => !empty($this->_params['imapflags'])
                    ));
                }
                break;

            case Ingo_Storage::ACTION_FLAGONLY:
                if (!empty($filter['flags'])) {
                    $action[] = new Ingo_Script_Sieve_Action_Addflag(array(
                        'flags' => $filter['flags'],
                        'imapflags' => !empty($this->_params['imapflags'])
                    ));
                }
                break;

            case Ingo_Storage::ACTION_NOTIFY:
                $action[] = new Ingo_Script_Sieve_Action_Notify(array(
                    'address' => $filter['action-value'],
                    'name' => $filter['name'],
                    'notify' => !empty($this->_params['notify'])
                ));
                break;

            case Ingo_Storage::ACTION_WHITELIST:
                $this->_addWhitelistBlocks();
                continue 2;

            case Ingo_Storage::ACTION_BLACKLIST:
                $this->_addBlacklistBlocks();
                continue 2;

            case Ingo_Storage::ACTION_VACATION:
                $this->_addVacationBlocks();
                continue 2;

            case Ingo_Storage::ACTION_FORWARD:
                $this->_addForwardBlocks();
                 continue 2;

            case Ingo_Storage::ACTION_SPAM:
                $this->_addSpamBlocks();
                continue 2;
            }

            $this->_addItem(Ingo::RULE_FILTER, new Ingo_Script_Sieve_Comment($filter['name']));

            if ($filter['stop']) {
                $action[] = new Ingo_Script_Sieve_Action_Stop();
            }

            if ($filter['combine'] == Ingo_Storage::COMBINE_ANY) {
                $test = new Ingo_Script_Sieve_Test_Anyof();
            } else {
                $test = new Ingo_Script_Sieve_Test_Allof();
            }

            foreach ($filter['conditions'] as $condition) {
                $tmp = '';
                switch ($condition['match']) {
                case 'equal':
                    $tmp = new Ingo_Script_Sieve_Test_Relational(array('comparison' => 'eq', 'headers' => $condition['field'], 'value' => $condition['value']));
                    $test->addTest($tmp);
                    break;

                case 'not equal':
                    $tmp = new Ingo_Script_Sieve_Test_Relational(array('comparison' => 'ne', 'headers' => $condition['field'], 'value' => $condition['value']));
                    $test->addTest($tmp);
                    break;

                case 'less than':
                    if ($condition['field'] == 'Size') {
                        /* Message Size Test. */
                        $tmp = new Ingo_Script_Sieve_Test_Size(array('comparison' => ':under', 'size' => $condition['value']));
                    } else {
                        /* Relational Test. */
                        $tmp = new Ingo_Script_Sieve_Test_Relational(array('comparison' => 'lt', 'headers' => $condition['field'], 'value' => $condition['value']));
                    }
                    $test->addTest($tmp);
                    break;

                case 'less than or equal to':
                    $tmp = new Ingo_Script_Sieve_Test_Relational(array('comparison' => 'le', 'headers' => $condition['field'], 'value' => $condition['value']));
                    $test->addTest($tmp);
                    break;

                case 'greater than':
                    if ($condition['field'] == 'Size') {
                        /* Message Size Test. */
                        $tmp = new Ingo_Script_Sieve_Test_Size(array('comparison' => ':over', 'size' => $condition['value']));
                    } else {
                        /* Relational Test. */
                        $tmp = new Ingo_Script_Sieve_Test_Relational(array('comparison' => 'gt', 'headers' => $condition['field'], 'value' => $condition['value']));
                    }
                    $test->addTest($tmp);
                    break;

                case 'greater than or equal to':
                    $tmp = new Ingo_Script_Sieve_Test_Relational(array('comparison' => 'ge', 'headers' => $condition['field'], 'value' => $condition['value']));
                    $test->addTest($tmp);
                    break;

                case 'exists':
                    $tmp = new Ingo_Script_Sieve_Test_Exists(array('headers' => $condition['field']));
                    $test->addTest($tmp);
                    break;

                case 'not exist':
                    $tmp = new Ingo_Script_Sieve_Test_Exists(array('headers' => $condition['field']));
                    $test->addTest(new Ingo_Script_Sieve_Test_Not($tmp));
                    break;

                case 'contains':
                case 'not contain':
                case 'is':
                case 'not is':
                case 'begins with':
                case 'not begins with':
                case 'ends with':
                case 'not ends with':
                case 'regex':
                case 'matches':
                case 'not matches':
                    $comparator = (isset($condition['case']) &&
                                   $condition['case'])
                        ? 'i;octet'
                        : 'i;ascii-casemap';
                    $vals = array('headers' => preg_replace('/(.)(?<!\\\)\,(.)/',
                                                            "$1\n$2",
                                                            $condition['field']),
                                  'comparator' => $comparator);
                    $use_address_test = false;

                    if ($condition['match'] != 'regex') {
                        $condition['value'] = preg_replace('/(.)(?<!\\\)\,(.)/',
                                                           "$1\n$2",
                                                           $condition['value']);
                    }

                    /* Do 'smarter' searching for fields where we know we have
                     * e-mail addresses. */
                    if (preg_match('/^(From|To|Cc|Bcc)/', $condition['field'])) {
                        $vals['addresses'] = $condition['value'];
                        $use_address_test = true;
                    } else {
                        $vals['strings'] = $condition['value'];
                    }

                    switch ($condition['match']) {
                    case 'contains':
                        $vals['match-type'] = ':contains';
                        if ($use_address_test) {
                            $tmp = new Ingo_Script_Sieve_Test_Address($vals);
                        } elseif ($condition['field'] == 'Body') {
                            $tmp = new Ingo_Script_Sieve_Test_Body($vals);
                        } else {
                            $tmp = new Ingo_Script_Sieve_Test_Header($vals);
                        }
                        $test->addTest($tmp);
                        break;

                    case 'not contain':
                        $vals['match-type'] = ':contains';
                        if ($use_address_test) {
                            $tmp = new Ingo_Script_Sieve_Test_Address($vals);
                        } elseif ($condition['field'] == 'Body') {
                            $tmp = new Ingo_Script_Sieve_Test_Body($vals);
                        } else {
                            $tmp = new Ingo_Script_Sieve_Test_Header($vals);
                        }
                        $test->addTest(new Ingo_Script_Sieve_Test_Not($tmp));
                        break;

                    case 'is':
                        $vals['match-type'] = ':is';
                        if ($use_address_test) {
                            $tmp = new Ingo_Script_Sieve_Test_Address($vals);
                        } elseif ($condition['field'] == 'Body') {
                            $tmp = new Ingo_Script_Sieve_Test_Body($vals);
                        } else {
                            $tmp = new Ingo_Script_Sieve_Test_Header($vals);
                        }
                        $test->addTest($tmp);
                        break;

                    case 'not is':
                        $vals['match-type'] = ':is';
                        if ($use_address_test) {
                            $tmp = new Ingo_Script_Sieve_Test_Address($vals);
                        } elseif ($condition['field'] == 'Body') {
                            $tmp = new Ingo_Script_Sieve_Test_Body($vals);
                        } else {
                            $tmp = new Ingo_Script_Sieve_Test_Header($vals);
                        }
                        $test->addTest(new Ingo_Script_Sieve_Test_Not($tmp));
                        break;

                    case 'begins with':
                        $vals['match-type'] = ':matches';
                        if ($use_address_test) {
                            $add_arr = preg_split('(\r\n|\n|\r)', $vals['addresses']);
                            if (count($add_arr) > 1) {
                                foreach ($add_arr as $k => $v) {
                                    $add_arr[$k] = $v . '*';
                                }
                                $vals['addresses'] = implode("\r\n", $add_arr);
                            } else {
                                $vals['addresses'] .= '*';
                            }
                            $tmp = new Ingo_Script_Sieve_Test_Address($vals);
                        } else {
                            $add_arr = preg_split('(\r\n|\n|\r)', $vals['strings']);
                            if (count($add_arr) > 1) {
                                foreach ($add_arr as $k => $v) {
                                    $add_arr[$k] = $v . '*';
                                }
                                $vals['strings'] = implode("\r\n", $add_arr);
                            } else {
                                $vals['strings'] .= '*';
                            }
                            if ($condition['field'] == 'Body') {
                                $tmp = new Ingo_Script_Sieve_Test_Body($vals);
                            } else {
                                $tmp = new Ingo_Script_Sieve_Test_Header($vals);
                            }
                        }
                        $test->addTest($tmp);
                        break;

                    case 'not begins with':
                        $vals['match-type'] = ':matches';
                        if ($use_address_test) {
                            $add_arr = preg_split('(\r\n|\n|\r)', $vals['addresses']);
                            if (count($add_arr) > 1) {
                                foreach ($add_arr as $k => $v) {
                                    $add_arr[$k] = $v . '*';
                                }
                                $vals['addresses'] = implode("\r\n", $add_arr);
                            } else {
                                $vals['addresses'] .= '*';
                            }
                            $tmp = new Ingo_Script_Sieve_Test_Address($vals);
                        } else {
                            $add_arr = preg_split('(\r\n|\n|\r)', $vals['strings']);
                            if (count($add_arr) > 1) {
                                foreach ($add_arr as $k => $v) {
                                    $add_arr[$k] = $v . '*';
                                }
                                $vals['strings'] = implode("\r\n", $add_arr);
                            } else {
                                $vals['strings'] .= '*';
                            }
                            if ($condition['field'] == 'Body') {
                                $tmp = new Ingo_Script_Sieve_Test_Body($vals);
                            } else {
                                $tmp = new Ingo_Script_Sieve_Test_Header($vals);
                            }
                        }
                        $test->addTest(new Ingo_Script_Sieve_Test_Not($tmp));
                        break;

                    case 'ends with':
                        $vals['match-type'] = ':matches';
                        if ($use_address_test) {
                            $add_arr = preg_split('(\r\n|\n|\r)', $vals['addresses']);
                            if (count($add_arr) > 1) {
                                foreach ($add_arr as $k => $v) {
                                    $add_arr[$k] = '*' . $v;
                                }
                                $vals['addresses'] = implode("\r\n", $add_arr);
                            } else {
                                $vals['addresses'] = '*' .  $vals['addresses'];
                            }
                            $tmp = new Ingo_Script_Sieve_Test_Address($vals);
                        } else {
                            $add_arr = preg_split('(\r\n|\n|\r)', $vals['strings']);
                            if (count($add_arr) > 1) {
                                foreach ($add_arr as $k => $v) {
                                    $add_arr[$k] = '*' . $v;
                                }
                                $vals['strings'] = implode("\r\n", $add_arr);
                            } else {
                                $vals['strings'] = '*' .  $vals['strings'];
                            }
                            if ($condition['field'] == 'Body') {
                                $tmp = new Ingo_Script_Sieve_Test_Body($vals);
                            } else {
                                $tmp = new Ingo_Script_Sieve_Test_Header($vals);
                            }
                        }
                        $test->addTest($tmp);
                        break;

                    case 'not ends with':
                        $vals['match-type'] = ':matches';
                        if ($use_address_test) {
                            $add_arr = preg_split('(\r\n|\n|\r)', $vals['addresses']);
                            if (count($add_arr) > 1) {
                                foreach ($add_arr as $k => $v) {
                                    $add_arr[$k] = '*' . $v;
                                }
                                $vals['addresses'] = implode("\r\n", $add_arr);
                            } else {
                                $vals['addresses'] = '*' .  $vals['addresses'];
                            }
                            $tmp = new Ingo_Script_Sieve_Test_Address($vals);
                        } else {
                            $add_arr = preg_split('(\r\n|\n|\r)', $vals['strings']);
                            if (count($add_arr) > 1) {
                                foreach ($add_arr as $k => $v) {
                                    $add_arr[$k] = '*' . $v;
                                }
                                $vals['strings'] = implode("\r\n", $add_arr);
                            } else {
                                $vals['strings'] = '*' .  $vals['strings'];
                            }
                            if ($condition['field'] == 'Body') {
                                $tmp = new Ingo_Script_Sieve_Test_Body($vals);
                            } else {
                                $tmp = new Ingo_Script_Sieve_Test_Header($vals);
                            }
                        }
                        $test->addTest(new Ingo_Script_Sieve_Test_Not($tmp));
                        break;

                    case 'regex':
                        $vals['match-type'] = ':regex';
                        if ($use_address_test) {
                            $tmp = new Ingo_Script_Sieve_Test_Address($vals);
                        } elseif ($condition['field'] == 'Body') {
                            $tmp = new Ingo_Script_Sieve_Test_Body($vals);
                        } else {
                            $tmp = new Ingo_Script_Sieve_Test_Header($vals);
                        }
                        $test->addTest($tmp);
                        break;

                    case 'matches':
                        $vals['match-type'] = ':matches';
                        if ($use_address_test) {
                            $tmp = new Ingo_Script_Sieve_Test_Address($vals);
                        } elseif ($condition['field'] == 'Body') {
                            $tmp = new Ingo_Script_Sieve_Test_Body($vals);
                        } else {
                            $tmp = new Ingo_Script_Sieve_Test_Header($vals);
                        }
                        $test->addTest($tmp);
                        break;

                    case 'not matches':
                        $vals['match-type'] = ':matches';
                        if ($use_address_test) {
                            $tmp = new Ingo_Script_Sieve_Test_Address($vals);
                        } elseif ($condition['field'] == 'Body') {
                            $tmp = new Ingo_Script_Sieve_Test_Body($vals);
                        } else {
                            $tmp = new Ingo_Script_Sieve_Test_Header($vals);
                        }
                        $test->addTest(new Ingo_Script_Sieve_Test_Not($tmp));
                        break;
                    }
                }
            }

            $if = new Ingo_Script_Sieve_If($test);
            $if->setActions($action);
            $this->_addItem(Ingo::RULE_FILTER, $if);
        }

        /* Add blocks that have to go to the end. */
        foreach ($this->_endBlocks as $block) {
            $this->_addItem(Ingo::RULE_FILTER, $block);
        }
    }
}
