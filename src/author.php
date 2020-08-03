<?php
// author.php -- HotCRP author objects
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Author {
    /** @var string */
    public $firstName = "";
    /** @var string */
    public $lastName = "";
    /** @var string */
    public $email = "";
    /** @var string */
    public $affiliation = "";
    /** @var ?string */
    private $_name;
    /** @var ?int */
    public $contactId;
    private $_deaccents;
    /** @var ?bool */
    public $nonauthor;
    /** @var ?int */
    public $paperId;
    /** @var ?int */
    public $conflictType;
    /** @var ?int */
    public $author_index;

    /** @param null|string|object $x */
    function __construct($x = null) {
        if (is_object($x)) {
            $this->firstName = $x->firstName;
            $this->lastName = $x->lastName;
            $this->email = $x->email;
            $this->affiliation = $x->affiliation;
        } else if ((string) $x !== "") {
            $this->assign_string($x);
        }
    }
    /** @return Author */
    static function make_tabbed($s) {
        $au = new Author;
        $w = explode("\t", $s);
        $au->firstName = $w[0] ?? "";
        $au->lastName = $w[1] ?? "";
        $au->email = $w[2] ?? "";
        $au->affiliation = $w[3] ?? "";
        return $au;
    }
    /** @param string $s
     * @return Author */
    static function make_string($s) {
        $au = new Author;
        $au->assign_string($s);
        return $au;
    }
    /** @param Author|Contact $o */
    function merge($o) {
        if ($this->email === "") {
            $this->email = $o->email;
        }
        if ($this->firstName === "" && $this->lastName === "") {
            $this->firstName = $o->firstName;
            $this->lastName = $o->lastName;
        }
        if ($this->affiliation === "") {
            $this->affiliation = $o->affiliation;
        }
    }
    /** @param string $s */
    function assign_string($s) {
        if (($paren = strpos($s, "(")) !== false) {
            if (preg_match('/\G([^()]*)(?:\)|\z)(?:[\s,;.]*|\s*(?:-+|–|—|[#:%]).*)\z/', $s, $m, 0, $paren + 1)) {
                $this->affiliation = trim($m[1]);
                $s = rtrim(substr($s, 0, $paren));
            } else {
                $len = strlen($s);
                while ($paren !== false) {
                    $rparen = self::skip_balanced_parens($s, $paren);
                    if ($rparen === $len
                        || preg_match('{\A(?:[\s,;.]*|\s*(?:-+|–|—|[#:]).*)\z}', substr($s, $rparen + 1))) {
                        $this->affiliation = trim(substr($s, $paren + 1, $rparen - $paren - 1));
                        $s = rtrim(substr($s, 0, $paren));
                        break;
                    }
                    $paren = strpos($s, "(", $rparen + 1);
                }
            }
        }
        $this->_name = trim($s);
        if (strlen($s) > 4
            || ($s !== "" && strcasecmp($s, "all") !== 0 && strcasecmp($s, "none") !== 0)) {
            list($this->firstName, $this->lastName, $this->email) = Text::split_name($s, true);
        }
    }
    /** @param string $s
     * @return Author */
    static function make_string_guess($s) {
        $au = new Author;
        $au->assign_string_guess($s);
        return $au;
    }
    /** @param string $s */
    function assign_string_guess($s) {
        $hash = strpos($s, "#");
        $pct = strpos($s, "%");
        if ($hash !== false || $pct !== false) {
            $s = substr($s, 0, $hash === false ? $pct : ($pct === false ? $hash : min($hash, $pct)));
        }
        $this->assign_string($s);
        if ($this->firstName === ""
            && (strcasecmp($this->lastName, "all") === 0
                || strcasecmp($this->lastName, "none") === 0)) {
            $this->lastName = "";
        }
        if ($this->affiliation === ""
            && $this->email === "") {
            if (strpos($s, ",") !== false
                && strpos($this->lastName, " ") !== false) {
                if (AuthorMatcher::is_likely_affiliation($this->firstName)) {
                    $this->affiliation = $this->firstName;
                    list($this->firstName, $this->lastName) = Text::split_name($this->lastName);
                }
            } else if (AuthorMatcher::is_likely_affiliation($s)) {
                $this->firstName = $this->lastName = "";
                $this->affiliation = $s;
            }
        }
    }
    static private $object_keys = [
        "firstName" => "firstName", "first" => "firstName",
        "givenName" => "firstName", "given" => "givenName",
        "lastName" => "lastName", "last" => "lastName",
        "familyName" => "lastName", "family" => "familyName",
        "name" => "name", "fullName" => "name",
        "email" => "email", "affiliation" => "affiliation"
    ];
    /** @param object|array<string,mixed> $o
     * @return Author */
    static function make_keyed($o) {
        $au = new Author;
        $au->assign_keyed($o);
        return $au;
    }
    /** @param object|array<string,mixed> $o */
    function assign_keyed($o) {
        if (!is_object($o) && !is_array($o)) {
            throw new Exception("invalid Author::make_keyed");
        }
        foreach (is_object($o) ? get_object_vars($o) : $o as $k => $v) {
            if (($mk = self::$object_keys[$k] ?? null) !== null
                && is_string($v)) {
                if ($mk === "name") {
                    $this->_name = $v;
                    list($this->firstName, $this->lastName, $e) = Text::split_name($v, true);
                    if ($e !== null && $this->email === "") {
                        $this->email = $e;
                    }
                } else {
                    $this->$mk = $v;
                }
            }
        }
    }
    /** @param string $email
     * @return Author */
    static function make_email($email) {
        $au = new Author;
        $au->email = $email;
        return $au;
    }
    /** @param string $s
     * @param int $paren
     * @return int */
    static function skip_balanced_parens($s, $paren) {
        // assert($s[$paren] === "("); -- precondition
        for ($len = strlen($s), $depth = 1, ++$paren; $paren < $len; ++$paren) {
            if ($s[$paren] === "(") {
                ++$depth;
            } else if ($s[$paren] === ")") {
                --$depth;
                if ($depth === 0) {
                    break;
                }
            }
        }
        return $paren;
    }
    /** @return string */
    function name($flags = 0) {
        if (($flags & (NAME_L | NAME_I | NAME_U)) === 0 && $this->_name !== null) {
            $name = $this->_name;
        } else {
            $name = Text::name($this->firstName, $this->lastName, $this->email, $flags);
        }
        if (($flags & NAME_A) !== 0 && $this->affiliation !== "") {
            $name = Text::add_affiliation($name, $this->affiliation, $flags);
        }
        return $name;
    }
    /** @return string */
    function name_h($flags = 0) {
        $name = htmlspecialchars($this->name($flags & ~NAME_A));
        if (($flags & NAME_A) !== 0 && $this->affiliation !== "") {
            $name = Text::add_affiliation_h($name, $this->affiliation, $flags);
        }
        return $name;
    }
    /** @return string */
    function deaccent($component) {
        if ($this->_deaccents === null) {
            $this->_deaccents = [
                strtolower(UnicodeHelper::deaccent($this->firstName)),
                strtolower(UnicodeHelper::deaccent($this->lastName)),
                strtolower(UnicodeHelper::deaccent($this->affiliation))
            ];
        }
        return $this->_deaccents[$component];
    }
    /** @return bool */
    function is_conflicted() {
        assert($this->conflictType !== null);
        return $this->conflictType > CONFLICT_MAXUNCONFLICTED;
    }
    /** @return string */
    function unparse_tabbed() {
        return "{$this->firstName}\t{$this->lastName}\t{$this->email}\t{$this->affiliation}";
    }
    /** @param Contact|Author $o
     * @return object */
    static function unparse_json_of($o) {
        $j = [];
        foreach (["email", "firstName", "lastName", "affiliation"] as $i => $k) {
            if ($o->$k !== "") {
                $jk = $i >= 1 && $i <= 2 ? substr($k, 0, -4) : $k;
                $j[$jk] = $o->$k;
            }
        }
        return (object) $j;
    }
}
