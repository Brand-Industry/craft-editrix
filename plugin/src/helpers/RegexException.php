<?php

namespace brandindustry\editrix\helpers;

/**
 * A regular expression that cannot be used on some text: it is invalid, or
 * it needs more work than the limit allows. The message is written for the
 * person who typed the pattern.
 */
class RegexException extends \RuntimeException
{
}
