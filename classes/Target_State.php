<?php
/**
 * The states a target in the index can be in.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

/**
 * The target states of GLOSSARY.md that the index holds.
 *
 * Published targets are never in the index; every other state is.
 *
 * @since 0.1.0
 */
enum Target_State: string {

	case Unpublished = 'unpublished';

	case Private = 'private';

	case Deleted = 'deleted';

}
