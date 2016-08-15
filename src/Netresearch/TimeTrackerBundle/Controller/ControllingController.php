<?php
/**
 * Netresearch Timetracker
 *
 * PHP version 5
 *
 * @category   Netresearch
 * @package    Timetracker
 * @subpackage Controller
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 * @link       http://www.netresearch.de
 */

namespace Netresearch\TimeTrackerBundle\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class ControllingController
 *
 * @category   Netresearch
 * @package    Timetracker
 * @subpackage Controller
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 * @link       http://www.netresearch.de
 */
class ControllingController extends BaseController
{

    /**
     * Exports a users timetable from one specific year and month
     *
     * @param Request $request
     *
     * @return Response
     */
    public function exportAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        $userId = $request->get('userid');
        $year   = $request->get('year');
        $month  = $request->get('month');

        $service = $this->get('nr.timetracker.export');
        $entries = $service->exportEntries(
            $userId, $year, $month, array(
                'user.username'  => true,
                'entry.day'   => true,
                'entry.start' => true,
            )
        );
        $username = $service->getUsername($userId);


        $content = $this->get('templating')->render(
            'NetresearchTimeTrackerBundle:Default:export.csv.twig',
            array(
                'entries' => $entries,
                'labels'  => $service->getLabelsForSingleColumns(),
            )
        );

        $filename = strtolower(
            $year . '_'
            . str_pad($month, 2, '0', STR_PAD_LEFT)
            . '_'
            . str_replace(' ', '-', $username)
            . '.csv'
        );

        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-disposition', 'attachment;filename=' . $filename);
        $response->setContent(chr(239) . chr(187) . chr(191) . $content);

        return $response;
    }

}
