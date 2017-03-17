<?php
/**
 * Created by PhpStorm.
 * User: mmoser
 * Date: 2017-03-02
 * Time: 18:21
 */

namespace CustomerManagementFramework\DuplicatesIndex;

use CustomerManagementFramework\DataSimilarityMatcher\BirthDate;
use CustomerManagementFramework\DataSimilarityMatcher\DataSimilarityMatcherInterface;
use CustomerManagementFramework\DataTransformer\DataTransformerInterface;
use CustomerManagementFramework\DataTransformer\DuplicateIndex\Standard;
use CustomerManagementFramework\Factory;
use CustomerManagementFramework\Model\CustomerInterface;
use CustomerManagementFramework\Plugin;
use CustomerManagementFramework\Traits\LoggerAware;
use Pimcore\Db;
use Pimcore\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class DefaultMariaDbDuplicatesIndex implements DuplicatesIndexInterface {

    use LoggerAware;

    const DUPLICATESINDEX_TABLE = 'plugin_cmf_duplicatesindex';
    const DUPLICATESINDEX_CUSTOMERS_TABLE = 'plugin_cmf_duplicatesindex_customers';
    const POTENTIAL_DUPLICATES_TABLE = 'plugin_cmf_potential_duplicates';
    const FALSE_POSITIVES_TABLE = 'plugin_cmf_duplicates_false_positives';

    protected $config;
    protected $duplicateCheckFields;
    protected $analyzeFalsePositives = false;

    public function __construct()
    {
        $this->config = Plugin::getConfig()->CustomerDuplicatesService->DuplicatesIndex;
        $this->duplicateCheckFields = $this->config->duplicateCheckFields ? $this->config->duplicateCheckFields->toArray() : [];
    }

    public function recreateIndex(LoggerInterface $logger)
    {
        $db = Db::get();
        $db->query("truncate table " . self::DUPLICATESINDEX_TABLE);
        $db->query("truncate table " . self::DUPLICATESINDEX_CUSTOMERS_TABLE);

        if($this->analyzeFalsePositives) {
            $db = Db::get();
            $db->query("truncate table " . self::FALSE_POSITIVES_TABLE);
        }

        $logger->notice("tables truncated");


        $customerList = Factory::getInstance()->getCustomerProvider()->getList();
        $customerList->setCondition("active = 1");
        $customerList->setOrderKey('o_id');

        $paginator = new \Zend_Paginator($customerList);
        $paginator->setItemCountPerPage(200);

        $totalPages = $paginator->getPages()->pageCount;
        for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++) {
            $logger->notice(sprintf("execute page %s of %s", $pageNumber, $totalPages));
            $paginator->setCurrentPageNumber($pageNumber);

            foreach($paginator as $customer) {

                $logger->notice(sprintf("update index for %s", (string) $customer));

                Factory::getInstance()->getCustomerDuplicatesService()->updateDuplicateIndexForCustomer($customer);

            }
        }
    }


    public function updateDuplicateIndexForCustomer(CustomerInterface $customer)
    {
        $duplicateDataRows = [];
        foreach($this->duplicateCheckFields as $fields) {
            $data = [];
            foreach($fields as $field => $options) {
                $getter = 'get' . ucfirst($field);
                $data[$field] = $this->transformDataForDuplicateIndex($customer->$getter(), $field);
            }


            $duplicateDataRows[] = $data;
        }

        $this->updateDuplicateIndex($customer->getId(), $duplicateDataRows, $this->duplicateCheckFields);
    }

    public function calculatePotentialDuplicates(OutputInterface $output)
    {
        if($this->analyzeFalsePositives) {
            $db = Db::get();
            $db->query("truncate table " . self::FALSE_POSITIVES_TABLE);
        }

        $this->getLogger()->notice("start calculating exact duplicate matches");
        $exakt = $this->calculateExactDuplicateMatches();

        $this->getLogger()->notice("start calculating fuzzy duplicate matches");
        $fuzzy = $this->calculateFuzzyDuplicateMatches($output);


        $total = [];

        foreach([$exakt, $fuzzy] as $dataSet) {
            foreach($dataSet as $fieldCombination => $items) {

                foreach($items as $item) {

                    $item = is_array($item) ? implode(',', $item) : $item;

                    $total[$item] = isset($total[$item]) ? $total[$item] : [];
                    $total[$item][] = $fieldCombination;

                }
            }
        }

        $this->getLogger()->notice("update potential duplicates table");

        $totalIds = [];
        foreach($total as $duplicateIds => $fieldCombinations) {

            if(!$id = Db::get()->fetchOne("select id from " . self::POTENTIAL_DUPLICATES_TABLE  . " where duplicateCustomerIds = ?", $duplicateIds)) {
                Db::get()->insert(self::POTENTIAL_DUPLICATES_TABLE, [
                    'duplicateCustomerIds' => $duplicateIds,
                    'fieldCombinations' => implode(';', array_unique((array)$fieldCombinations)),
                    'creationDate' => time()
                ]);

                $id = Db::get()->lastInsertId();
            }

            $totalIds[] = $id;
        }


        Factory::getInstance()->getLogger()->notice("delete potential duplicates which are not valid anymore");
        Db::get()->query("delete from " . self::POTENTIAL_DUPLICATES_TABLE . " where id not in(" . implode(',', $totalIds) . ")");
    }

    /**
     * @return bool
     */
    public function getAnalyzeFalsePositives()
    {
        return $this->analyzeFalsePositives;
    }

    /**
     * @param bool $analyseFalsePositives
     */
    public function setAnalyzeFalsePositives($analyzeFalsePositives)
    {
        $this->analyzeFalsePositives = $analyzeFalsePositives;
    }



    protected function calculateExactDuplicateMatches()
    {
        $db = Db::get();

        $duplicateIds = $db->fetchCol("select duplicate_id from " . self::DUPLICATESINDEX_CUSTOMERS_TABLE . " group by duplicate_id having count(*) > 1 order by count(*) desc");

        $result = [];

        foreach($duplicateIds as $duplicateId) {
            $customerIds = $db->fetchCol("select customer_id from " . self::DUPLICATESINDEX_CUSTOMERS_TABLE . " where duplicate_id = ? order by customer_id", $duplicateId);

            $fieldCombination = $db->fetchOne("select fieldCombination from " . self::DUPLICATESINDEX_TABLE . " where id = ?", $duplicateId);
            $result[$fieldCombination][] = $customerIds;
        }

        return $result;
    }

    protected function calculateFuzzyDuplicateMatches(OutputInterface $output)
    {

        $metaphone = $this->calculateFuzzyDuplicateMatchesByAlgorithm("metaphone", $output); // 5268
        $soundex = $this->calculateFuzzyDuplicateMatchesByAlgorithm("soundex", $output); //5602

        $resultSets = [$metaphone, $soundex];

        $result = [];
        foreach($resultSets as $resultSet) {
            foreach($resultSet as $fieldCombination => $duplicateClusters) {
                $result[$fieldCombination] = isset($result[$fieldCombination]) ? $result[$fieldCombination] : [];

                $result[$fieldCombination] = array_merge((array) $result[$fieldCombination], $duplicateClusters);
            }
        }

        return $result;
    }

    protected function calculateFuzzyDuplicateMatchesByAlgorithm($algorithm, OutputInterface $output)
    {
        $db = Db::get();

        $phoneticDuplicates = $db->fetchCol("select `" . $algorithm . "` from " . self::DUPLICATESINDEX_TABLE . " where `" . $algorithm . "` is not null and `" . $algorithm . "` != '' group by `" . $algorithm . "` having count(*) > 1");

        $result = [];

        $totalCount = sizeof($phoneticDuplicates);

        $output->writeln('');
        $this->getLogger()->notice(sprintf("calculate potential duplicates for %s", $algorithm));

        $progress = new ProgressBar($output, $totalCount);
        $progress->setFormat('verbose');

        foreach($phoneticDuplicates as $phoneticDuplicate) {

            $progress->advance();

            $rows = $db->fetchAll("select * from " . self::DUPLICATESINDEX_CUSTOMERS_TABLE . " c, " . self::DUPLICATESINDEX_TABLE . " i where i.id = c.duplicate_id and `" . $algorithm . "` = ?  order by customer_id", $phoneticDuplicate);

            $customerIdClusters = $this->extractSimilarCustomerIdClustersGroupedByFieldCombinations($rows);

            foreach($customerIdClusters as $fieldCombination => $clusters) {
                foreach($clusters as $cluster) {
                    $result[$fieldCombination][] = $cluster;
                }
            }
        }

        $progress->finish();
        $output->writeln('');
        $output->writeln('');

        return $result;
    }

    protected function extractSimilarCustomerIdClustersGroupedByFieldCombinations($rows) {

        $groupedByFieldCombination = [];
        foreach($rows as $row) {
            $groupedByFieldCombination[$row['fieldCombination']] = isset($groupedByFieldCombination[$row['fieldCombination']]) ? $groupedByFieldCombination[$row['fieldCombination']] : [];
            $groupedByFieldCombination[$row['fieldCombination']][] = $row;
        }

        $result = [];
        foreach($groupedByFieldCombination as $fieldCombination => $fieldCombinationRows) {
            $result[$fieldCombination] = $this->extractSimilarCustomerIdClusters($fieldCombinationRows);

        }


        return $result;
    }

    private function extractSimilarCustomerIdClusters($rows) {

        $result = [];

        if(!$this->rowsAreSimilar($rows)) {

            // if not all rows are similar try to find similar duplicates pairwise
            if(sizeof($rows) > 2) {
                foreach($this->getCombinations($rows, 2) as $combination) {
                    if($clusters = $this->extractSimilarCustomerIdClusters($combination)) {
                        foreach($clusters as $cluster) {
                            $result[] = $cluster;
                        }

                    }
                }
            }

            return $result;
        }

        $cluster = [];
        foreach($rows as $row) {
            $cluster[] = $row['customer_id'];
        }

        $result[] = $cluster;

        return $result;
    }

    protected function getCombinations($base,$n){

        $baselen = count($base);
        if($baselen == 0){
            return;
        }
        if($n == 1){
            $return = array();
            foreach($base as $b){
                $return[] = array($b);
            }
            return $return;
        }else{
            //get one level lower combinations
            $oneLevelLower = $this->getCombinations($base,$n-1);

            //for every one level lower combinations add one element to them that the last element of a combination is preceeded by the element which follows it in base array if there is none, does not add
            $newCombs = array();

            foreach($oneLevelLower as $oll){

                $lastEl = $oll[$n-2];
                $found = false;
                foreach($base as  $key => $b){
                    if($b == $lastEl){
                        $found = true;
                        continue;
                        //last element found

                    }
                    if($found == true){
                        //add to combinations with last element
                        if($key < $baselen){

                            $tmp = $oll;
                            $newCombination = array_slice($tmp,0);
                            $newCombination[]=$b;
                            $newCombs[] = array_slice($newCombination,0);
                        }

                    }
                }

            }

        }

        return $newCombs;


    }

    /**
     * @param array $rows
     *
     * return bool
     */
    protected function rowsAreSimilar(array $rows) {

        if(sizeof($rows) < 2) {
            return false;
        }

        $firstRow = $rows[0];

        unset($rows[0]);

        $fieldCombinationConfig = $this->getFieldCombinationConfig($firstRow['fieldCombination']);

        foreach($rows as $row) {
            if(!$this->twoRowsAreSimilar($firstRow, $row, $fieldCombinationConfig)) {

                Factory::getInstance()->getLogger()->debug("false positive: " . json_encode($firstRow['duplicateData']) . ' | ' . json_encode($row['duplicateData']));

                if($this->analyzeFalsePositives) {
                    Db::get()->insert(self::FALSE_POSITIVES_TABLE, [
                        "row1" => $firstRow['duplicateData'],
                        "row2" => $row['duplicateData'],
                        "row1Details" => json_encode($firstRow),
                        "row2Details" => json_encode($row),
                    ]);
                }

                return false;
            } else {

                Factory::getInstance()->getLogger()->debug("potential duplicate found: " . $firstRow['duplicate_id'] . ' | ' . $row['duplicate_id']);
            }
        }


        return true;
    }

    protected function twoRowsAreSimilar(array $row1, array $row2, array $fieldCombinationConfig) {

        // fuzzy matching is only enabled if at least one field has a similitry option configured
        $applies = false;
        foreach($fieldCombinationConfig as $field => $options) {
            if ($options['similarity']) {
                $applies = true;
                break;
            }
        }

        if(!$applies) {
            return false;
        }

        foreach($fieldCombinationConfig as $field => $options) {
            if($options['similarity']) {

                $similarityMatcher = $this->getSimilarityMatcher($options['similarity']);

                $dataRow1 = json_decode($row1['duplicateData'], true);
                $dataRow2 = json_decode($row2['duplicateData'], true);

                $treshold = isset($options['similarityTreshold']) ? $options['similarityTreshold'] : null;

                if(!$similarityMatcher->isSimilar($dataRow1[$field], $dataRow2[$field], $treshold)) {
                    return false;
                }
            }
        }

        return true;
    }

    private $similarityMatchers = [];
    /**
     * @param string $similiarity
     * @return DataSimilarityMatcherInterface
     */
    protected function getSimilarityMatcher($similiarity) {
        if(!isset($this->similarityMatchers[$similiarity])) {
            $this->similarityMatchers[$similiarity] = Factory::getInstance()->createObject($similiarity, DataSimilarityMatcherInterface::class);
        }

        return $this->similarityMatchers[$similiarity];
    }

    private $fieldCombinationConfig = [];
    /**
     * @param string $fieldCombinationCommaSeparated
     * @return array
     */
    protected function getFieldCombinationConfig($fieldCombinationCommaSeparated) {

        if(!isset($this->fieldCombinationConfig[$fieldCombinationCommaSeparated])) {
            $this->fieldCombinationConfig[$fieldCombinationCommaSeparated] = [];
            foreach ($this->duplicateCheckFields as $fields) {
                $fieldCombination = explode(',', $fieldCombinationCommaSeparated);

                if (sizeof($fields) != sizeof($fieldCombination)) {
                    continue;
                }

                $matched = true;
                foreach ($fieldCombination as $field) {
                    $iterationMatched = false;
                    foreach ($fields as $fieldKey => $trash) {
                        if ($fieldKey == $field) {
                            $iterationMatched = true;
                        }
                    }

                    if (!$iterationMatched) {
                        $matched = false;
                    }
                }

                if ($matched) {
                    $this->fieldCombinationConfig[$fieldCombinationCommaSeparated] = $fields;
                    break;
                }
            }
        }

        return $this->fieldCombinationConfig[$fieldCombinationCommaSeparated];
    }

    protected function updateDuplicateIndex($customerId, array $duplicateDataRows, array $fieldCombinations) {
        $db = Db::get();
        $db->beginTransaction();
        try {

            $db->query("delete from " . self::DUPLICATESINDEX_CUSTOMERS_TABLE . " where customer_id = ?", $customerId);

            foreach($duplicateDataRows as $index => $duplicateDataRow) {
                $valid = true;
                foreach($duplicateDataRow as $val) {
                    if(!trim($val)) {
                        $valid = false;
                        break;
                    }
                }
                if(!$valid) {
                    break;
                }

                $data = json_encode($duplicateDataRow);
                $fieldCombination = implode(',', array_keys($fieldCombinations[$index]));

                $dataMd5 = md5($data);
                $fieldCombinationCrc = crc32($fieldCombination);



                if(!$duplicateId = $db->fetchOne("select id from " . self::DUPLICATESINDEX_TABLE . " WHERE duplicateDataMd5 = ? and fieldCombinationCrc = ?", [$dataMd5, $fieldCombinationCrc])) {

                    $db->insert(self::DUPLICATESINDEX_TABLE, [
                        'duplicateData' => $data,
                        'duplicateDataMd5' => $dataMd5,
                        'fieldCombination' => $fieldCombination,
                        'fieldCombinationCrc' => $fieldCombinationCrc,
                        'soundex' =>  $this->getPhoneticHashData($duplicateDataRow, $fieldCombinations[$index], 'soundex'),
                        'metaphone' => $this->getPhoneticHashData($duplicateDataRow, $fieldCombinations[$index], 'metaphone'),
                        'creationDate' => time(),
                    ]);

                    $duplicateId = $db->lastInsertId();
                }

                $db->insert(self::DUPLICATESINDEX_CUSTOMERS_TABLE, [
                    'customer_id' => $customerId,
                    'duplicate_id' => $duplicateId
                ]);


            }

            $db->commit();

        }  catch(\Exception $e) {
            $db->rollBack();
            Logger::error($e->getMessage());
        }
    }

    protected function getPhoneticHashData($customerData, $fieldOptions, $algorithm) {
        $data = [];
        foreach($fieldOptions as $field => $options) {
            if($options[$algorithm]) {
                $data[] = $customerData[$field];
            }
        }

        if(!sizeof($data)) {
            return null;
        }
        foreach($data as $key => $value) {
            if($algorithm == 'soundex') {
                $data[$key] = soundex($value);
            } elseif($algorithm == 'metaphone') {
                $data[$key] = metaphone($value);
            }
        }

        return implode('', $data);
    }


    protected function transformDataForDuplicateIndex($data, $field) {

        if(!$class = $this->config->dataTransformers->{$field}) {
            $class = Standard::class;
        }

        $transformer = Factory::getInstance()->createObject($class, DataTransformerInterface::class);

        return $transformer->transform($data);
    }
}