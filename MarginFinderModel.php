<?php

class MarginFinder
{

    /*
     * @return mixed
     * Gets list of all leagues in the database. Returns array of names or false on failure
     */
    public function getLeagues()
    {
        $results = DB::table('leagues')->select('name')->lists('name');
        if (!empty($results)) {
            return $results;
        }
        return false;
    }

    /*
     * @return mixed
     * Returns id of the league given the league name as a string
     * or false on failure
     */
    private function getLeagueIdByName($league)
    {
        $results = DB::table('leagues')->where('name', '=', $league)->orwhere('description', 'like', $league)->pluck(
            'id'
        );
        if (!empty($results)) {
            return $results;
        }

        return false;
    }

    /*
     * @return mixed
     * Returns an array of teams for the specified league
     * or false on failure
     */
    private function getLeagueTeams($league)
    {
        $teams = DB::table('teams')->select('teams.id', 'teams.name AS name')->join(
            'leagues',
            'leagues.id',
            '=',
            'teams.league_id'
        )->where('leagues.name', '=', $league)->orderBy('name')->get();

        if (empty($teams)) {
            return false;
        }
        return $teams;
    }

    /*
     * @return mixed
     * Array will contain all section data for a game using the game id
     * or false on failure
     */
    private function getSectionsByGameId($game_id)
    {
        $sections = DB::table('section_prices')->select('id', 'stubhub_event_id')->join(
            'games',
            'section_prices.game_id',
            '=',
            'games.id'
        )->where('games.id', '=', $game_id)->get();

        if (empty($sections)) {
            return false;
        }
        return $sections;
    }

    /*
     * @return mixed
     * Returns an array of all stubhub section data for a game using the stubhub event id
     * or false on failure
     */
    private function getStubhubSectionData($stubhubEventId)
    {
        $stubhubSectionData = DB::table('stubhub_prices')->select('minTicketPrice', 'avgTicketPrice')->where('event_id', '=', $stubhubEventId)->get();

        if (empty($stubhubSectionData)) {
            return false;
        }
        return $stubhubSectionData;
    }

    /*
     * @return mixed
     * Returns an array of game data for all upcoming home games of the season for a team
     * or false on failure
     * Only games at least 1 day in the future will be shown.
     */
    private function getUpcomingHomeGames($teamId)
    {
        $games = DB::table('games')
            ->select('games.date', 'games.home_team', 'games.away_team', 'games.time', 'games.id')
            ->distinct()
            ->join('craigslist_listings', 'craigslist_listings.game_id', '=', 'games.id')
            ->where('games.date', '>', date('Y-m-d', strtotime('+1 day')))
            ->where('home_team_id', '=', $teamId)
            ->orderBy('games.date', 'ASC')->get();

        if (empty($games)) {
            return false;
        }
        return $games;

    }

    /*
     * @return mixed
     * Returns an array of section data for a specific section using the section id
     * or false boolean on failure
     */
    private function getSectionData($sectionId)
    {
        $sections = DB::table('section_prices')->select('min_price', 'imageUrl')->where('id', '=', $sectionId)->get();
        if (!empty($sections)) {
            return $sections;
        }

        return false;
    }


    /*
     * @return boolean
     * Updates section prices for a specific team using the team id.
     * It will update the sections for upcoming games in the next two weeks.
     * returns false if a section could not be updated. Returns true if all sections were updated.
     */
    public function updateTeamSections($teamId)
    {
        //prices are unique to the specific game so gather all upcoming games.
        //We only need the home team since that will cover all possible games instead of looping through all teams.
        $upcoming_games = $this->getUpcomingHomeGames($teamId);

        //loop through upcoming games to update prices of the section.
        foreach ($upcoming_games as $index => $gameData) {
            $result = $this->updateSectionPricesByGameId($gameData->game_id);

            //The section wasn't updated so add it to the logs.
            if (empty($result)) {
                //a section was not able to be updated. log error and return false.
                Log::warning('Could not update sections for game. ', array('Game ID:' => $gameData->game_id));
                return false;
            }
        }
        return true;
    }


    /*
     * @return boolean
     * Updates section prices using the game id.
     * Returns true if all sections were able to be updated.
     * Returns false if any of the sections failed to update.
     */
    private function updateSectionPricesByGameId($game_id)
    {
        //find all sections within the specific game.
        $sections = $this->getSectionsByGameId($game_id);

        if (!empty($sections)) {
            //loop through each individual section for the game.
            foreach ($sections as $key => $values) {

                //update specific section
                $result = $this->updateSectionBySectionId($values->section_id, $values->stubhub_event_id);
                if (empty($result)) {
                    //section was not updated. log error and return false.
                    Log::warning('Could not update sections for game. ', array('Game id:' => $game_id));
                    return false;
                }
            }
            //all sections were able to be updated. Return true.
            return true;
        }

        //section data could not be found. return false.
        Log::warning('Could not find section data for game. ', array('game Id:' => $game_id));
        return false;
    }


    /*
     * Returns true if the section was successfully updated or if the price did not need updating.
     * Returns false if there was an error updating the section or if it could not gather data for the sections.
     */
    private function updateSectionBySectionId($sectionId, $stubhubEventId)
    {

        //gather stubhub and currently listed prices for the section.
        $stubhubSectionData = $this->getStubhubSectionData($stubhubEventId);
        $sectionData = $this->getSectionData($sectionId);

        if (!empty($stubhubSectionData) && !empty($sectionData)) {

            //check if the price has changed. Round stubhub price as they include cents. We only need dollar values.
            if ($sectionData->min_price !== round($stubhubSectionData->minTicketPrice)) {

                //update the price of the section to the new stubhub price listed.
                $update = DB::table('section_prices')->where('sections_id', '=', $sectionId)->update(
                    array('min_price' => $stubhubSectionData->minTicketPrice)
                );
                if (empty($update)) {
                    Log::warning('Could not update section price.', array('section id:' => $sectionId));
                    return false;
                }
                //successfully updated
                return true;
            }
            //section prices match, don't update and return true.
            return true;
        }
        //could not find the section or stubhub data for section, log error and return false.
        Log::warning(
            'Could not find section or stubhub section data ',
            array('section data:' => $stubhubSectionData, 'stubhub_data' => $stubhubSectionData)
        );
        return false;
    }


}