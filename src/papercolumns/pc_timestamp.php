<?php
// pc_timestamp.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Timestamp_PaperColumn extends PaperColumn {
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $at = max($a->timeFinalSubmitted, $a->timeSubmitted, 0);
        $bt = max($b->timeFinalSubmitted, $b->timeSubmitted, 0);
        return $at > $bt ? -1 : ($at == $bt ? 0 : 1);
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return max($row->timeFinalSubmitted, $row->timeSubmitted) <= 0;
    }
    function content(PaperList $pl, PaperInfo $row) {
        if (($t = max($row->timeFinalSubmitted, $row->timeSubmitted, 0)) > 0) {
            return $row->conf->unparse_time($t);
        }
        return "";
    }
}
