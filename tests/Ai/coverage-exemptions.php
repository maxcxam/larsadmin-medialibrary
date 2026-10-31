<?php

/*
|--------------------------------------------------------------------------
| Skill coverage exemptions (ADR-046)
|--------------------------------------------------------------------------
|
| Elements of the public surface that SkillCoverageTest does not require the
| larsadmin-medialibrary skill to mention, as "element => reason". The element
| is written exactly as the failing test prints it, e.g.:
|
|   'class Maxcxam\LarsadminMedialibrary\Example' => 'Why the skill skips it.',
|   'member Media::example' => '...',
|   'guide docs/guide/en/example.md' => '...',
|   'invariant Maxcxam\LarsadminMedialibrary\Example' => '...',
|
| Every entry needs a reason and must name an element that still exists.
| The list must be empty when a release is tagged.
|
*/

return [
];
