<?php

/*
 * Created by Tyler Adams
 * This script will update section prices for all sections of a stadium for all teams within the specified league.
 * It will compare the craigslist prices to the current prices on stubhub and update them accordingly.
 *
 * Note: Prices from craigslist and stubhub are imported into a MySQL database in a separate script using API's,
 * so that the API limits aren't reached each time this script runs.
 */
class MarginfinderController extends BaseController
{

    public function __construct(MarginFinder $marginfinder)
    {
        $this->marginfinder = $marginfinder;
    }

    /*
     * @return boolean
     * Update the sections for each team in a league.
     * Returns an array of results using the team id as the index and result of each game update.
     * The count of Results[teamid] will match the remaining games left on the schedule for that team if all updates
     * were successful.
     *
     */
    public function updateSectionsByLeague($league)
    {
        //gather all teams in league
        $teams = $this->marginfinder->getLeagueTeams($league);

        if (!empty($teams)) {
            $results = array();

            //loop through all teams in the league and update the team's prices of each section for all upcoming games.
            foreach ($teams as $key => $teamData) {
                $results[$teamData->team_id][] = $this->marginfinder->updateTeamSections($teamData->team_id);
            }

            return $results;
        }

        //could not find teams for league.
        Log::warning('Could not find teams for specified league ', array('League:' => $league));
        return false;
    }

}