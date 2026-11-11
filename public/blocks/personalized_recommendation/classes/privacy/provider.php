<?php
// This file is part of Moodle - http://moodle.org/

namespace block_personalized_recommendation\privacy;

use core_privacy\local\metadata\collection;

/**
 * Privacy provider. Nothing is stored in Moodle, but the user id is sent to an external service.
 *
 * @package    block_personalized_recommendation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\provider {
    /**
     * Describe the external data flow.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link('recsys_api', [
            'userid' => 'privacy:metadata:recsys_api:userid',
        ], 'privacy:metadata:recsys_api');
        return $collection;
    }
}
