<?php
/**
 * Created by PhpStorm.
 * User: kzumueller
 * Date: 2017-09-12
 * Time: 2:03 PM
 */

namespace CustomerManagementFrameworkBundle\Controller\Admin;

use CustomerManagementFrameworkBundle\SegmentAssignment\SegmentAssigner\SegmentAssignerInterface;
use CustomerManagementFrameworkBundle\SegmentManager\SegmentManagerInterface;
use Pimcore\Bundle\AdminBundle\Controller\AdminController;
use Pimcore\Bundle\AdminBundle\HttpFoundation\JsonResponse;
use Pimcore\Db\Connection;
use Pimcore\Model\DataObject\CustomerSegment;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class SegmentAssignmentController
 *
 * @Route("/segment-assignment")
 *
 * @package CustomerManagementFrameworkBundle\Controller\Admin
 */
class SegmentAssignmentController extends AdminController {

    /**
     * @Route("/inheritable-segments")
     * @param Request $request
     * @return JsonResponse
     */
    public function inheritableSegments(Request $request) {
        $id = $request->get('id') ?? '';
        $type = $request->get('type') ?? '';

        /* @var $db Connection */
        $db = $this->get(Connection::class);
        $parentIdStatement = sprintf('SELECT `%s` FROM `%s` WHERE `%s` = "%s"', $type === 'object' ? 'o_parentId' : 'parentId', $type.'s', $type === 'object' ? 'o_id' : 'id', $id);
        $parentId = $db->fetchOne($parentIdStatement);

        $segments = $this->get(SegmentManagerInterface::class)->getSegmentsForElementId($parentId, $type);
        $data = array_map([$this, 'dehydrateSegment'], $segments);

        return $this->json(['data' => $data]);
    }

    /**
     * returns directly assigned segmentIds for the pimcore backend
     *
     * @Route("/assigned-segments")
     * @param Request $request
     * @return JsonResponse
     */
    public function assignedSegments(Request $request) {
        $id = $request->get('id') ?? '';
        $type = $request->get('type') ?? '';
        $assignmentTable = $this->getParameter('cmf.segmentAssignment.table.raw');
        $segmentIds = $this->get(Connection::class)->fetchOne("SELECT `segments` FROM $assignmentTable WHERE `elementId` = ? AND `elementType` = ?", [$id, $type]);

        $data = array_map(function($id){
            $segment = CustomerSegment::getById($id);
            return $this->dehydrateSegment($segment);
        }, explode(',', $segmentIds));

        return $this->json(['data' => array_filter($data)]);
    }

    /**
     * saves assignments asynchronously
     *
     * @Route("/assign")
     * @param Request $request
     * @return JsonResponse
     */
    public function assign(Request $request) {
        $id = $request->get('id') ?? '';
        $type = $request->get('type') ?? '';
        $breaksInheritance = $request->get('breaksInheritance') === 'true';
        $segmentIds = json_decode($request->get('segmentIds'), true) ?? [];

        $assigner = $this->get(SegmentAssignerInterface::class);
        $success = $assigner->assignById($id, $type, $breaksInheritance, $segmentIds);

        return $this->json($success);
    }

    /**
     * @Route("/breaks-inheritance")
     * @param Request $request
     * @return JsonResponse
     */
    public function breaksInheritance(Request $request) {
        $id = $request->get('id') ?? '';
        $type = $request->get('type') ?? '';
        $assignmentTable = $this->getParameter('cmf.segmentAssignment.table.raw');

        $breaksInheritance = $this->get(Connection::class)->fetchOne("SELECT `breaksInheritance` FROM $assignmentTable WHERE `elementId` = ? AND `elementType` = ?", [$id, $type]);

        return $this->json(['breaksInheritance' => $breaksInheritance]);
    }

    /**
     * @param CustomerSegment $segment
     * @return array
     */
    private function dehydrateSegment($segment): array {
        if(!($segment instanceof CustomerSegment)) {
            return [];
        }

        return [
            'id' => $segment->getId(),
            'type' => $segment->getType(),
            'name' => $segment->getName(),
        ];
    }
}