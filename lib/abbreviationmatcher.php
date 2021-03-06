<?php
// abbreviationmatcher.php -- HotCRP abbreviation matcher helper class
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

// NEW MATCH PRIORITY (higher = more priority)
// All matches are case-insensitive, ignore differences in accents, and ignore
// most punctuation.
//
// If pattern does not contain *:
//    +3 no skipped non-stopwords, all full word matches
//    +2 no skipped non-stopwords, match not stored as keyword
//    +1 all full word matches, match not stored as keyword
//    +0 otherwise
// If pattern contains *:
//    +1 no skipped non-stopwords at beginning and pattern doesn't start with *
//    +0 otherwise
//
// Patterns that look like CamelCaseWords are separated at case boundaries.
// Digit sequences are not prefix-matched, so a pattern like “X1” will not
// match subject “X 100”.
//
// The subject list can be “deparenthesized”, allowing parenthesized expressions
// to be skipped without penalty. See `add_deparenthesized`.

// OLD match priority (higher = more priority):
// 5. Exact match
// 4. Exact match with [-_.–—] replaced by spaces
// 3. Case-insensitive match with [-_.–—] replaced by spaces
// 2. Case-insensitive word match with [-_.–—] replaced by spaces
// 1. Case-insensitive CamelCase match with [-_.–—] replaced by spaces
//
// If a word match is performed, prefer matches that match more complete words.
// Words must appear in order, so pattern "hello kitty" does not match "kitty
// hello".

class AbbreviationMatchTracker {
    /** @var bool
     * @readonly */
    private $isu;
    /** @var string
     * @readonly */
    private $pattern;
    /** @var string
     * @readonly */
    private $dpattern;
    /** @var string
     * @readonly */
    private $upattern;
    /** @var string
     * @readonly */
    private $dupattern;
    private $imatchre;
    /** @var bool
     * @readonly */
    private $is_camel_word;
    /** @var 0|1|2
     * @readonly */
    private $has_star;
    private $camelwords;
    private $mclass = 1;
    private $matches = [];

    function __construct($pattern, $isu = null) {
        if ($isu === null) {
            $isu = !is_usascii($pattern);
        }
        $this->isu = $isu;
        if ($isu) {
            $this->pattern = UnicodeHelper::normalize($pattern);
            $this->dpattern = AbbreviationMatcher::dedash($this->pattern);
            $this->upattern = UnicodeHelper::deaccent($this->pattern);
            $this->dupattern = AbbreviationMatcher::dedash($this->upattern);
        } else {
            $this->pattern = $this->upattern = $pattern;
            $this->dpattern = $this->dupattern = AbbreviationMatcher::dedash($pattern);
        }
        $this->is_camel_word = AbbreviationMatcher::is_camel_word_old($pattern);
        $starpos = strpos($pattern, "*");
        if ($starpos === false) {
            $this->has_star = 0;
        } else if ($starpos === 0) {
            $this->has_star = 2;
        } else {
            $this->has_star = 1;
        }
    }
    private function wmatch_score($pattern, $subject, $flags) {
        // assert($pattern whitespace is simplified)
        $pwords = explode(" ", $pattern);
        $swords = preg_split('/\s+/', $subject);
        $pword = "";
        $pword_pos = -1;
        $pword_star = false;
        $ppos = $spos = $demerits = $skipped = 0;
        while (isset($pwords[$ppos]) && isset($swords[$spos])) {
            if ($pword_pos !== $ppos) {
                $pword = '{\A' . preg_quote($pwords[$ppos]) . '([^-\s,.:;\'"\[\]{}()!?&]*).*\z}' . $flags;
                $pword_pos = $ppos;
                if ($this->has_star !== 0
                    && strpos($pwords[$ppos], "*") !== false) {
                    $pword = str_replace('\\*', '.*', $pword);
                    $pword_star = true;
                } else {
                    $pword_star = false;
                }
            }
            if (preg_match($pword, $swords[$spos], $m)) {
                ++$ppos;
                $demerits += $m[1] !== "" || $pword_star;
            } else if ($this->has_star !== 2) {
                $skipped = 1;
            }
            ++$spos;
        }
        // missed words cost 1/64 point, partial words cost 1/64 point
        if (!isset($pwords[$ppos])) {
            if ($skipped || ($this->has_star === 0 && $spos < count($swords))) {
                $demerits += 4;
            }
            //error_log("- $subject $this->pattern $demerits $ppos $spos");
            return 1 - 0.015625 * min($demerits + 1, 63);
        } else {
            return 0;
        }
    }
    private function camel_wmatch_score($subject) {
        assert($this->is_camel_word);
        if (!$this->camelwords) {
            $this->camelwords = [];
            $x = $this->pattern;
            while (preg_match('/\A[-_.]*([a-z]+|[A-Z][a-z]*|[0-9]+)(.*)\z/', $x, $m)) {
                $this->camelwords[] = $m[1];
                $this->camelwords[] = $m[2] !== "" && ctype_alnum(substr($m[2], 0, 1));
                $x = $m[2];
            }
        }
        $swords = preg_split('/\s+/', $subject);
        $ppos = $spos = $demerits = $skipped = 0;
        while (isset($this->camelwords[$ppos]) && isset($swords[$spos])) {
            $pword = $this->camelwords[$ppos];
            $sword = $swords[$spos];
            $ppos1 = $ppos;
            $sidx = 0;
            while ($sidx + strlen($pword) <= strlen($sword)
                   && strcasecmp($pword, substr($sword, $sidx, strlen($pword))) === 0) {
                $sidx += strlen($pword);
                $ppos += 2;
                if (!$this->camelwords[$ppos - 1]) {
                    break;
                }
                $pword = $this->camelwords[$ppos];
            }
            if ($sidx !== 0) {
                $demerits += $sidx < strlen($sword);
            } else {
                ++$skipped;
            }
            ++$spos;
        }
        if (!isset($this->camelwords[$ppos])) {
            if ($skipped || ($this->has_star === 0 && $spos < count($swords))) {
                $demerits += 4;
            }
            //error_log("+ $subject $this->pattern $demerits $ppos $spos");
            return 1 - 0.015625 * min($demerits + 1, 63);
        } else {
            return 0;
        }
    }
    /** @param string $subject
     * @param ?bool $sisu
     * @return int|float */
    private function mclass($subject, $sisu = null) {
        if ($sisu === null) {
            $sisu = !is_usascii($subject);
        }

        if ($this->isu && $sisu) {
            if ($this->pattern === $subject) {
                return 9;
            } else if ($this->mclass >= 9) {
                return 0;
            }

            $dsubject = AbbreviationMatcher::dedash($subject);
            if ($this->dpattern === $dsubject) {
                return 8;
            } else if ($this->mclass >= 7) {
                return 0;
            }

            if (!$this->imatchre) {
                $this->imatchre = '{\A' . preg_quote($this->dpattern) . '\z}iu';
            }
            if (preg_match($this->imatchre, $dsubject)) {
                return 7;
            } else if (($s = $this->wmatch_score($this->dpattern, $dsubject, "iu"))) {
                return 6 + $s;
            }
        }

        if ($this->mclass >= 6) {
            return 0;
        }

        $usubject = $sisu ? UnicodeHelper::deaccent($subject) : $subject;
        if ($this->upattern === $usubject) {
            return 5;
        } else if ($this->mclass >= 5) {
            return 0;
        }

        $dusubject = AbbreviationMatcher::dedash($usubject);
        if ($this->dupattern === $dusubject) {
            return 4;
        } else if ($this->mclass >= 4) {
            return 0;
        }

        if (strcasecmp($this->dupattern, $dusubject) === 0) {
            return 3;
        } else if ($this->mclass >= 3) {
            return 0;
        }

        $s1 = $this->wmatch_score($this->dupattern, $dusubject, "i");
        $s2 = $this->is_camel_word ? $this->camel_wmatch_score($dusubject) : 0;
        if ($s1 || $s2) {
            return 1 + max($s1, $s2);
        } else {
            return 0;
        }
    }

    function check($subject, $data, $sisu = null) {
        $mclass = $this->mclass($subject, $sisu);
        //if ($mclass > 0) error_log("$subject : {$this->pattern} : $mclass");
        if ($mclass > $this->mclass) {
            $this->mclass = $mclass;
            $this->matches = [$data];
        } else if ($mclass == $this->mclass
                   && $this->matches[count($this->matches) - 1] !== $data) {
            $this->matches[] = $data;
        }
    }

    function matches() {
        return $this->matches;
    }
}

/** @template T */
class AbbreviationEntry {
    /** @var string
     * @readonly */
    public $name;
    /** @var ?string */
    public $dedash_name;
    /** @var ?T
     * @readonly */
    public $value;
    /** @var int
     * @readonly */
    public $tflags;
    /** @var callable(...):T
     * @readonly */
    public $loader;
    /** @var list<mixed>
     * @readonly */
    public $loader_args;

    const TFLAG_KW = 0x10000000;

    /** @param string $name
     * @param T $value
     * @param int $tflags */
    function __construct($name, $value, $tflags = 0) {
        $this->name = $name;
        $this->value = $value;
        $this->tflags = $tflags;
    }

    /** @template T
     * @param string $name
     * @param callable(...):T $loader
     * @param list<mixed> $loader_args
     * @param int $tflags
     * @return AbbreviationEntry<T>
     * @suppress PhanAccessReadOnlyProperty */
    static function make_lazy($name, $loader, $loader_args, $tflags = 0) {
        $x = new AbbreviationEntry($name, null, $tflags);
        $x->loader = $loader;
        $x->loader_args = $loader_args;
        return $x;
    }

    /** @return T
     * @suppress PhanAccessReadOnlyProperty */
    function value() {
        if ($this->value === null && $this->loader !== null) {
            $this->value = call_user_func_array($this->loader, $this->loader_args);
            assert($this->value !== null);
        }
        return $this->value;
    }
}

/** @template T */
class AbbreviationMatcher {
    /** @var list<AbbreviationEntry> */
    private $data = [];
    /** @var int */
    private $nanal = 0;
    /** @var int */
    private $ndeparen = 0;
    /** @var array<string,list<int>> */
    private $matches = [];
    /** @var array<int,float> */
    private $prio = [];

    /** @var list<string> */
    private $ltesters = [];
    /** @var array<string,list<int>> */
    private $xmatches = [];
    /** @var array<string,list<string>> */
    private $lxmatches = [];

    /** @param T $template */
    function __construct($template = null) {
    }

    private function add_entry(AbbreviationEntry $e, $isphrase) {
        $i = count($this->data);
        $this->data[] = $e;
        if (!($e->tflags & AbbreviationEntry::TFLAG_KW)) {
            $this->matches = $this->xmatches = $this->lxmatches = [];
            if ($isphrase
                && strpos($e->name, " ") === false
                && self::is_strict_camel_word($e->name)) {
                $e2 = clone $e;
                /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
                $e2->name = preg_replace('/([a-z\'](?=[A-Z])|[A-Z](?=[A-Z][a-z]))/', '$1 ', $e->name);
                $this->data[] = $e2;
            }
        } else if ($this->nanal === $i) {
            $e->dedash_name = self::dedash($e->name);
            $lname = strtolower($e->name);
            $this->ltesters[] = " " . $lname;
            $this->matches[$e->name] = [$i];
            foreach ($this->lxmatches[$lname] ?? [] as $n) {
                unset($this->xmatches[$n]);
            }
            unset($this->xmatches[$lname], $this->lxmatches[$lname]);
            ++$this->nanal;
        }
        return $e;
    }
    /** @param string $name
     * @param T $data
     * @return AbbreviationEntry */
    function add_phrase($name, $data, int $tflags = 0) {
        $name = simplify_whitespace(UnicodeHelper::deaccent($name));
        return $this->add_entry(new AbbreviationEntry($name, $data, $tflags), true);
    }
    /** @param string $name
     * @param callable(...):T $loader
     * @param list $loader_args
     * @return AbbreviationEntry */
    function add_phrase_lazy($name, $loader, $loader_args, int $tflags = 0) {
        $name = simplify_whitespace(UnicodeHelper::deaccent($name));
        return $this->add_entry(AbbreviationEntry::make_lazy($name, $loader, $loader_args, $tflags), true);
    }
    /** @param string $name
     * @param T $data
     * @return AbbreviationEntry */
    function add_keyword($name, $data, int $tflags = 0) {
        assert(strpos($name, " ") === false);
        return $this->add_entry(new AbbreviationEntry($name, $data, $tflags | AbbreviationEntry::TFLAG_KW), false);
    }
    /** @param string $name
     * @param callable(...):T $loader
     * @param list $loader_args
     * @return AbbreviationEntry */
    function add_keyword_lazy($name, $loader, $loader_args, int $tflags = 0) {
        assert(strpos($name, " ") === false);
        return $this->add_entry(AbbreviationEntry::make_lazy($name, $loader, $loader_args, $tflags | AbbreviationEntry::TFLAG_KW), false);
    }
    function add_deparenthesized() {
        $this->_analyze();
        $n = count($this->data);
        while ($this->ndeparen !== $this->nanal) {
            $e = $this->data[$this->ndeparen];
            if (($e->tflags & AbbreviationEntry::TFLAG_KW) === 0
                && ($s = self::deparenthesize($e->name)) !== ""
                && !in_array(self::make_xtester(strtolower($s)), $this->ltesters)) {
                $e = clone $e;
                /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
                $e->name = $s;
                $this->data[] = $e;
            }
            ++$this->ndeparen;
        }
        $this->ndeparen = count($this->data);
        if ($this->ndeparen !== $n) {
            $this->matches = $this->xmatches = $this->lxmatches = [];
        }
    }

    function set_priority(int $tflags, float $prio) {
        $this->prio[$tflags] = $prio;
    }

    /** @param string $s
     * @return string */
    static function dedash($s) {
        return preg_replace('/(?:[-_.\s]|–|—)+/', " ", $s);
    }
    /** @param string $s
     * @return bool */
    static function is_camel_word_old($s) {
        return preg_match('/\A[-_.A-Za-z0-9]*(?:[A-Za-z](?=[-_.A-Z0-9])|[0-9](?=[-_.A-Za-z]))[-_.A-Za-z0-9]*\*?\z/', $s);
    }
    /** @param string $s
     * @return bool */
    static function is_camel_word($s) {
        return preg_match('/\A[_.A-Za-z0-9~?!\'*]*(?:[A-Za-z][_.A-Z0-9]|[0-9][_.A-Za-z])[_.A-Za-z0-9~?!\'*]*\z/', $s);
    }
    /** @param string $s
     * @return bool */
    static function is_strict_camel_word($s) {
        return preg_match('/\A[A-Za-z0-9~?!\']*(?:[a-z\'][A-Z]|[A-Z][A-Z][a-z])[.A-Za-z0-9~?!\']*\z/', $s);
    }
    /** @param string $s
     * @return string */
    static function make_xtester($s) {
        if (strpbrk($s, "\'()[]") !== false) {
            preg_match_all('/(?:\A_+|)[A-Za-z~?!][A-Za-z~?!\'()\[\]]*|(?:[0-9]|\.[0-9])[0-9.]*/', $s, $m);
            if (!empty($m[0])) {
                return preg_replace('/[\'()\[\]]/', "", " " . join(" ", $m[0]));
            } else {
                return "";
            }
        } else {
            preg_match_all('/(?:\A_+|)[A-Za-z~?!][A-Za-z~?!]*|(?:[0-9]|\.[0-9])[0-9.]*/', $s, $m);
            if (!empty($m[0])) {
                return " " . join(" ", $m[0]);
            } else {
                return "";
            }
        }
    }
    /** @param string $s
     * @param bool $case_sensitive
     * @return string */
    static function xtester_remove_stops($s, $case_sensitive = false) {
        return preg_replace('/ (?:a|an|and|are|at|be|been|can|did|do|for|has|how|if|in|is|isnt|it|new|of|on|or|that|the|their|they|this|to|we|were|what|which|with|you)(?= |\z)/i', "", $s);
    }
    /** @param string $name
     * @return string */
    static private function deparenthesize($name) {
        if ((strpos($name, "(") !== false || strpos($name, "[") !== false)
            && ($xname = preg_replace('/(?:\s+|\A)(?:\(.*?\)|\[.*?\])(?=\s|\z)/', "", $name)) !== ""
            && $xname !== $name) {
            return $xname;
        } else {
            return "";
        }
    }

    /** @suppress PhanAccessReadOnlyProperty */
    private function _analyze() {
        assert($this->nanal === count($this->ltesters));
        while ($this->nanal < count($this->data)) {
            $d = $this->data[$this->nanal];
            $d->dedash_name = self::dedash($d->name);
            $lname = strtolower($d->name);
            if (($d->tflags & AbbreviationEntry::TFLAG_KW) !== 0) {
                $this->ltesters[] = " " . $lname;
            } else {
                $this->ltesters[] = self::make_xtester($lname);
            }
            ++$this->nanal;
        }
    }

    private function _find_all($pattern) {
        if (empty($this->matches)) {
            $this->_analyze();
        }

        $spat = $upat = simplify_whitespace($pattern);
        if (($sisu = !is_usascii($spat))) {
            $spat = UnicodeHelper::normalize($spat);
            $upat = UnicodeHelper::deaccent($spat);
        }
        $dupat = self::dedash($upat);
        if (self::is_camel_word_old($upat)) {
            $re = preg_replace('/([A-Za-z](?=[A-Z0-9 ])|[0-9](?=[A-Za-z ]))/', '$1(?:|.*\b)', $dupat);
            $re = '{\b' . str_replace(" ", "", $re) . '}i';
        } else {
            $re = join('.*\b', preg_split('/[^A-Za-z0-9*]+/', $dupat));
            $re = '{\b' . str_replace("*", ".*", $re) . '}i';
        }

        $mclass = 0;
        $matches = [];
        foreach ($this->data as $i => $d) {
            if (strcasecmp($dupat, $d->dedash_name) === 0) {
                if ($mclass === 0) {
                    $matches = [];
                }
                $mclass = 1;
                $matches[] = $i;
            } else if ($mclass === 0 && preg_match($re, $d->dedash_name)) {
                $matches[] = $i;
            }
        }

        if (count($matches) > 1) {
            $amt = new AbbreviationMatchTracker($spat, $sisu);
            foreach ($matches as $i) {
                $d = $this->data[$i];
                $amt->check($d->name, $i, strlen($d->name) !== strlen($d->dedash_name));
            }
            $matches = $amt->matches();
        }

        $this->matches[$pattern] = $matches;
    }

    private function _xfind_all($pattern) {
        if (empty($this->xmatches)) {
            $this->_analyze();
        }

        $upat = $pattern;
        $lpattern = strtolower($pattern);
        if (!is_usascii($upat)) {
            $upat = UnicodeHelper::deaccent(UnicodeHelper::normalize($upat));
        }

        $re = '';
        $npatternw = 0;
        $iscamel = self::is_camel_word($upat);
        if ($iscamel) {
            preg_match_all('/(?:\A_+|)[A-Za-z~][a-z~?!]+|[A-Z][A-Z]*(?![a-z])|(?:[0-9]|\.[0-9])[0-9.]*/', $upat, $m);
            //error_log($upat . " " . join(",", $m[0]));
            $sep = " ";
            foreach ($m[0] as $w) {
                $re .= $sep;
                $sep = "(?:.*? )??";
                if (strlen($w) > 1 && ctype_upper($w)) {
                    $re .= join($sep, str_split($w));
                    $npatternw += strlen($w) - 1;
                } else {
                    $re .= preg_quote($w, "/");
                }
                if (ctype_digit($w[strlen($w) - 1])) {
                    $re .= "(?![0-9])";
                }
                ++$npatternw;
            }
        } else {
            preg_match_all('/(?:\A_+|)[A-Za-z~?!*][A-Za-z~?!*]*|(?:[0-9]|\.[0-9])[0-9.]*/', $upat, $m);
            $sep = " ";
            foreach ($m[0] as $w) {
                $re .= $sep . preg_quote($w, "/");
                if (ctype_digit($w[strlen($w) - 1])) {
                    $re .= "(?![0-9])";
                }
                $sep = ".*? ";
                ++$npatternw;
            }
        }

        $re = strtolower($re);
        $starpos = strpos($upat, "*");
        if ($starpos !== false) {
            $re = '/' . str_replace('\\*', '.*', $re) . '/s';
        } else if (strpos($lpattern, " ") !== false) {
            $re = '/' . $re . '/s';
        } else {
            $re = '/\A ' . preg_quote($lpattern, "/") . '\z|' . $re . '/s';
        }
        $full_match_length = strlen($lpattern) + 1;

        $xt = preg_grep($re, $this->ltesters);
        if (count($xt) > 1 && $starpos !== 0) {
            //error_log("! $re " . json_encode($xt));
            $status = 0;
            $xtx = [];
            if ($iscamel) {
                $re = str_replace("(?:.*? )??", "((?:.*? )??)", $re);
            } else {
                $re = str_replace(".*?", "(.*?)", $re);
            }
            if (!str_ends_with($upat, "*")) {
                $re = substr($re, 0, -2) . '(.*)/s';
                ++$npatternw;
            }
            //error_log("! $re");
            foreach (array_keys($xt) as $i) {
                $t = $this->ltesters[$i];
                $iskw = ($this->data[$i]->tflags & AbbreviationEntry::TFLAG_KW) !== 0;
                preg_match($re, $t, $m);
                // check for missing words
                $skips = "";
                if ($m[0] !== $t) {
                    $skips = substr($t, 0, strlen($t) - strlen($m[0]));
                }
                // compute status. if no star:
                //    +3 no skipped non-stopwords, full word matches
                //    +2 no skipped non-stopwords, not stored as keyword
                //    +1 full word matches, not stored as keyword
                //    +0 otherwise
                // if star:
                //    +1 no skipped non-stopwords at beginning
                //    +0 otherwise
                if ($starpos !== false) {
                    $this_status = self::xtester_remove_stops($skips) === "" ? 1 : 0;
                } else if ($skips === "" && strlen($t) === $full_match_length) {
                    $this_status = 3;
                } else {
                    $full_words = true;
                    for ($j = 1; $j < $npatternw; ++$j) {
                        $x = $m[$j];
                        if ($x !== "" && $x[0] !== " ") {
                            $full_words = false;
                        }
                        $sp = strpos($x, " ");
                        if ($sp !== false && $sp !== strlen($x) - 1) {
                            $end = strlen($x) - (str_ends_with($x, " ") ? 1 : 0);
                            $skips .= substr($x, $sp, $end - $sp);
                        }
                    }
                    $noskips = self::xtester_remove_stops($skips) === "";
                    if ($noskips && $full_words) {
                        $this_status = 3;
                    } else if ($noskips && !$iskw) {
                        $this_status = 2;
                    } else if ($full_words && !$iskw) {
                        $this_status = 1;
                    } else {
                        $this_status = 0;
                    }
                }
                //error_log("! $re $t $this_status:$status S<$skips>");
                if ($this_status > $status) {
                    $xtx = [$i];
                    $status = $this_status;
                } else if ($this_status === $status) {
                    $xtx[] = $i;
                }
            }
        } else {
            $xtx = array_keys($xt);
        }

        $this->xmatches[$pattern] = $xtx;
        if ($lpattern !== $pattern) {
            $this->lxmatches[$lpattern][] = $pattern;
        }
    }

    private function match_entries($m, $tflags) {
        $r = [];
        $prio = $tflags ? ($this->prio[$tflags] ?? false) : false;
        foreach ($m as $i) {
            $d = $this->data[$i];
            $dprio = $this->prio[$d->tflags & 255] ?? 0.0;
            if ($prio === false || $dprio > $prio) {
                $r = [];
                $prio = $dprio;
            }
            if ((!$tflags || ($d->tflags & $tflags) !== 0) && $prio == $dprio) {
                $r[] = $d;
            }
        }
        return $r;
    }

    static private function compress_entries($r) {
        $n = count($r);
        for ($i = 1; $i < $n; ) {
            if (($r[$i]->value ?? $r[$i]->value()) === ($r[$i - 1]->value ?? $r[$i - 1]->value())) {
                array_splice($r, $i, 1);
                --$n;
            } else {
                ++$i;
            }
        }
        return $r;
    }

    function nentries() {
        return count($this->data);
    }

    /** @param string $pattern
     * @param int $tflags
     * @return list<AbbreviationEntry> */
    function find_entries($pattern, $tflags = 0) {
        if (!array_key_exists($pattern, $this->xmatches)) {
            $this->_xfind_all($pattern);
        }
        return $this->match_entries($this->xmatches[$pattern], $tflags);
    }

    /** @param string $pattern
     * @param int $tflags
     * @return list<T> */
    function find_all($pattern, $tflags = 0) {
        if (!array_key_exists($pattern, $this->xmatches)) {
            $this->_xfind_all($pattern);
            $this->_find_all($pattern);
            if ($this->matches[$pattern] !== $this->xmatches[$pattern]
                && !empty($this->matches[$pattern])) {
                $r1 = self::compress_entries($this->match_entries($this->matches[$pattern], $tflags));
                $r2 = self::compress_entries($this->match_entries($this->xmatches[$pattern], $tflags));
                $same = count($r1) === count($r2);
                for ($i = 0; $same && $i !== count($r1); ++$i) {
                    $same = $r1[$i]->value === $r2[$i]->value;
                }
                if (!$same) {
                    error_log(Conf::$main->dbname . ": matching $pattern: old "
                        . json_encode(array_map(function ($d) { return $d->name; }, $r1))
                        . " vs. new "
                        . json_encode(array_map(function ($d) { return $d->name; }, $r2)));
                }
            }
        }
        $results = [];
        $prio = $tflags ? ($this->prio[$tflags] ?? false) : false;
        foreach ($this->xmatches[$pattern] as $i) {
            $d = $this->data[$i];
            $dprio = $this->prio[$d->tflags & 255] ?? 0.0;
            if ($prio === false || $dprio > $prio) {
                $results = [];
                $prio = $dprio;
            }
            if ((!$tflags || ($d->tflags & $tflags) !== 0) && $prio == $dprio) {
                $value = $d->value ?? $d->value();
                if (empty($results) || !in_array($value, $results, true)) {
                    $results[] = $value;
                }
            }
        }
        return $results;
    }

    /** @param string $pattern
     * @param int $tflags
     * @return ?T */
    function find1($pattern, $tflags = 0) {
        $a = $this->find_all($pattern, $tflags);
        return count($a) === 1 ? $a[0] : null;
    }

    /** @param string $pattern
     * @param int $tflags
     * @return list<T> */
    function findp($pattern, $tflags = 0) {
        $a = $this->find_all($pattern, $tflags);
        if (count($a) <= 1 || strpos($pattern, "*") !== false) {
            return $a;
        } else {
            return [];
        }
    }


    private function test_all_matches($pattern, AbbreviationEntry $test, $tflags) {
        if ($pattern === "") {
            return false;
        }
        $n = $nok = 0;
        foreach ($this->find_entries($pattern, $tflags) as $e) {
            ++$n;
            if ($test->tflags === ($e->tflags & 255)
                && ($test->value !== null
                    ? $test->value === $e->value
                    : $test->loader === $e->loader && $test->loader_args === $e->loader_args)) {
                ++$nok;
            }
        }
        //error_log(". $pattern $n $nok");
        return $n !== 0 && $n === $nok;
    }

    /** @param int $n
     * @param int|false $sp */
    static private function camel_contract($s, $n, $sp, $hasnum) {
        while ($n > 0) {
            $sp = strpos($s, " ", $sp + 1);
            --$n;
        }
        $s = substr($s, 1, $sp === false ? strlen($s) - 1 : $sp - 1);
        if ($hasnum) {
            $s = preg_replace('/(\d) (\d)/', '$1_$2', $s);
        }
        return str_replace(" ", "", $s);
    }

    /** @suppress PhanAccessReadOnlyProperty */
    private function _finish_abbreviation($cname, AbbreviationEntry $e, $class, $csp) {
        if ($class === (self::KW_CAMEL | self::KW_ENSURE)
            && $csp > 1
            && !$this->find_entries(strtolower($cname), 0)) {
            $e2 = clone $e;
            $e2->name = strtolower($cname);
            $e2->tflags |= AbbreviationEntry::TFLAG_KW;
            $this->add_entry($e2, false);
        }
        return $cname;
    }

    const KW_CAMEL = 0;
    const KW_DASH = 1;
    const KW_UNDERSCORE = 2;
    const KW_ENSURE = 4;
    /** @param int $class
     * @param int $tflags
     * @return string|false
     * @suppress PhanAccessReadOnlyProperty */
    function find_entry_keyword(AbbreviationEntry $e, $class, $tflags = 0) {
        // Strip parenthetical remarks when that preserves uniqueness
        $name = simplify_whitespace(UnicodeHelper::deaccent($e->name));
        if (($xname = self::deparenthesize($name)) !== ""
            && $this->test_all_matches($xname, $e, $tflags)) {
            $name = $xname;
        }
        // Translate to xtester
        $name = self::make_xtester($name);
        // Strip stop words when that preserves uniqueness
        if (substr_count($name, " ") > 2
            && ($sname = self::xtester_remove_stops($name)) !== ""
            && strlen($sname) !== strlen($name)
            && $this->test_all_matches($sname, $e, $tflags)) {
            $name = $sname;
        }
        // Obtain an abbreviation by type
        if (($class & 3) === self::KW_CAMEL) {
            $cname = ucwords($name);
            $csp = substr_count($cname, " ");
            // check for a CamelWord we should separate
            if ($csp === 1
                && self::is_strict_camel_word(substr($name, 1))) {
                $cname = ucwords(preg_replace('/([a-z\'](?=[A-Z])|[A-Z](?=[A-Z][a-z]))/', '$1 ', $cname));
                $csp = substr_count($cname, " ");
            }
            if ($csp === 1) {
                // only one word
                $xcname = substr($cname, 1, strlen($cname) < 7 ? 6 : 3);
                if ($this->test_all_matches($xcname, $e, $tflags)) {
                    return $this->_finish_abbreviation($xcname, $e, $class, $csp);
                }
                $cname = substr($cname, 1);
            } else {
                $hasnum = strpbrk($cname, "0123456789") !== false;
                $cname = preg_replace('/([A-Z][a-z][a-z])[A-Za-z~!?]*/', '$1', $cname);
                if ($csp > 3) {
                    // try first three words
                    $xcname = self::camel_contract($cname, 3, 0, $hasnum);
                    if ($this->test_all_matches($xcname, $e, $tflags)) {
                        return $this->_finish_abbreviation($xcname, $e, $class, $csp);
                    }
                    // try successive groups of four words
                    $icname = $cname;
                    for ($sp = $csp; $sp >= 4; --$sp) {
                        $spos = strpos($icname, " ", 1);
                        $xcname = self::camel_contract($icname, 3, $spos, $hasnum);
                        if ($this->test_all_matches($xcname, $e, $tflags)) {
                            return $this->_finish_abbreviation($xcname, $e, $class, $csp);
                        }
                        $icname = substr($icname, $spos);
                    }
                }
                $cname = self::camel_contract($cname, 0, false, $hasnum);
            }
        } else if (($class & 3) === self::KW_UNDERSCORE) {
            $cname = str_replace(" ", "_", strtolower(substr($name, 1)));
            $csp = 0;
        } else {
            $cname = str_replace(" ", "-", strtolower(substr($name, 1)));
            $csp = 0;
        }
        // Add suffix
        if ($this->test_all_matches($cname, $e, $tflags)) {
            return $this->_finish_abbreviation($cname, $e, $class, $csp);
        } else if (($class & self::KW_ENSURE) !== 0) {
            $cname .= ".";
            $suffix = 1;
            while ($this->find_entries($cname . $suffix, 0)) {
                ++$suffix;
            }
            $e2 = clone $e;
            $e2->name = $cname . $suffix;
            $e2->tflags |= AbbreviationEntry::TFLAG_KW;
            $this->add_entry($e2, false);
            return $cname . $suffix;
        } else {
            return false;
        }
    }

    /** @param int $class
     * @param int $tflags
     * @return string|false */
    function ensure_entry_keyword(AbbreviationEntry $e, $class, $tflags = 0) {
        return $this->find_entry_keyword($e, $class | self::KW_ENSURE, $tflags);
    }
}
