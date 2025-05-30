<?php

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

class ContractControllerTest extends AbstractWebTestCase
{
    public function testSaveContractAction()
    {
        $parameter = [
            'user_id' => '1', //req
            'start' => '2025-11-01', //req
            'hours_0' => 1,
            'hours_1' => 2,
            'hours_2' => 3,
            'hours_3' => 4.3,
            'hours_4' => 5,
            'hours_5' => 6,
            'hours_6' => 7,
        ];
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '2025-11-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 1,
                'start' => '2025-11-01',
                'hours_0' => 1.0,
                'hours_1' => 2.0,
                'hours_2' => 3.0,
                'hours_3' => 4.3,
                'hours_4' => 5.0,
                'hours_5' => 6.0,
                'hours_6' => 7.0,
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
        // test old contract updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '2020-02-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 1,
                'start' => '2020-02-01',
                'end' => '2025-10-31'
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveContractActionStartNotFirstOfMonth()
    {
        $parameter = [
            'user_id' => '1', //req
            'start' => '2025-11-11', //req
            'hours_0' => 1,
            'hours_1' => 2,
            'hours_2' => 3,
            'hours_3' => 4.3,
            'hours_4' => 5,
            'hours_5' => 6,
            'hours_6' => 7,
        ];
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '2025-11-11');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 1,
                'start' => '2025-11-11',
                'hours_0' => 1.0,
                'hours_1' => 2.0,
                'hours_2' => 3.0,
                'hours_3' => 4.3,
                'hours_4' => 5.0,
                'hours_5' => 6.0,
                'hours_6' => 7.0,
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
        // test old contract updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '2020-02-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 1,
                'start' => '2020-02-01',
                'end' => '2025-11-10'
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveContractActionAlterExistingContract()
    {
        $parameter = [
            'user_id' => '3', //req
            'start' => '0700-08-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // look at old contract
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '0700-01-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 3,
                'start' => '0700-01-01',
                'end' => '0700-07-31',
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveContractActionOldContractStartsDuringNewWithoutEnd()
    {
        $parameterContract1 = [
            'user_id' => '3', //req
            'start' => '2020-01-01', //req
            'end' => '2020-03-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', //req
            'start' => '2019-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request('POST', '/contract/save', $parameterContract2);
        $this->assertStatusCode(406);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Startdatum in der Zukunft, das sich mit dem neuen Vertrag überschneidet.');
    }

    public function testSaveContractActionOldContractStartsDuringNew()
    {
        $parameterContract1 = [
            'user_id' => '3', //req
            'start' => '2020-01-01', //req
            'end' => '2020-03-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', //req
            'start' => '2019-01-01', //req
            'end' => '2020-02-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request('POST', '/contract/save', $parameterContract2);
        $this->assertStatusCode(406);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Startdatum in der Zukunft, das sich mit dem neuen Vertrag überschneidet.');
    }

    public function testSaveContractActionOldContractWithoutEndStartsDuringNew()
    {
        $parameterContract1 = [
            'user_id' => '3', //req
            'start' => '2020-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', //req
            'start' => '2019-01-01', //req
            'end' => '2022-03-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request('POST', '/contract/save', $parameterContract2);
        $this->assertStatusCode(406);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Startdatum in der Zukunft, das sich mit dem neuen Vertrag überschneidet.');
    }

    public function testSaveContractActionOldContractWithoutEndStartsDuringNewWithoutEnd()
    {
        $parameterContract1 = [
            'user_id' => '3', //req
            'start' => '2020-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', //req
            'start' => '2019-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request('POST', '/contract/save', $parameterContract2);
        $this->assertStatusCode(406);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Startdatum in der Zukunft, das sich mit dem neuen Vertrag überschneidet.');
    }

    public function testSaveContractActionOldContractEndStartsDuringNewEndWithNewContractEndingAfterOld()
    {
        $parameterContract1 = [
            'user_id' => '3', //req
            'start' => '2020-01-01', //req
            'end' => '2022-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', //req
            'start' => '2019-01-01', //req
            'end' => '2027-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request('POST', '/contract/save', $parameterContract2);
        $this->assertStatusCode(406);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Startdatum in der Zukunft, das sich mit dem neuen Vertrag überschneidet.');
    }

    public function testSaveContractActionNewContractStartsDuringOldWithEnd()
    {
        $parameterContract1 = [
            'user_id' => '3', //req
            'start' => '2020-01-01', //req
            'end' => '2022-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', //req
            'start' => '2021-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request('POST', '/contract/save', $parameterContract2);
        $this->assertStatusCode(406);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Enddatum in der Zukunft.');
    }

    public function testSaveContractActionNewContractWithEndDuringOldStartsDuringOldWithEnd()
    {
        $parameterContract1 = [
            'user_id' => '3', //req
            'start' => '2020-01-01', //req
            'end' => '2024-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', //req
            'start' => '2021-01-01', //req
            'end' => '2022-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request('POST', '/contract/save', $parameterContract2);
        $this->assertStatusCode(406);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Enddatum in der Zukunft.');
    }

    public function testSaveContractActionNewContractWithEndAfterOldStartsDuringOldWithEnd()
    {
        $parameterContract1 = [
            'user_id' => '3', //req
            'start' => '2020-01-01', //req
            'end' => '2024-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', //req
            'start' => '2021-01-01', //req
            'end' => '2030-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request('POST', '/contract/save', $parameterContract2);
        $this->assertStatusCode(406);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Enddatum in der Zukunft.');
    }

    public function testSaveContractActionOldContractStartsInFutureAfterNewEnds()
    {
        $parameterContract = [
            'user_id' => '3', //req
            'start' => '500-01-01', //req
            'end' => '600-01-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // test old contract not updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '0700-01-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 3,
                'start' => '0700-01-01',
                'end' => NULL
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveContractActionOldContractWithEndStartsInFutureAfterNewEnds()
    {
        $parameterContract = [
            'user_id' => '2', //req
            'start' => '500-01-01', //req
            'end' => '600-01-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // test old contract not updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '1020-01-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 2,
                'start' => '1020-01-01',
                'end' => '2020-01-01'
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveContractActionOldContractWithEndbeforeStartNewContract()
    {
        $parameterContract = [
            'user_id' => '2', //req
            'start' => '5000-01-01', //req
            'end' => '6000-01-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // test old contract not updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '1020-01-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 2,
                'start' => '1020-01-01',
                'end' => '2020-01-01'
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveContractActionUpdateOldContract()
    {
        $parameterContract = [
            'user_id' => '3', //req
            'start' => '5000-01-01', //req
            'end' => '6000-01-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // test old contract updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '700-01-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 3,
                'start' => '0700-01-01',
                'end' => '4999-12-31',
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveContractActionUpdateOldContractNewWithoutEnd()
    {
        $parameterContract = [
            'user_id' => '3', //req
            'start' => '5000-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // test old contract updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '700-01-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 3,
                'start' => '0700-01-01',
                'end' => '4999-12-31',
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveContractActionOldContractWithEndbeforeStartNewContractOpenEnd()
    {
        $parameterContract = [
            'user_id' => '2', //req
            'start' => '5000-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // test old contract not updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '1020-01-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 2,
                'start' => '1020-01-01',
                'end' => '2020-01-01'
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveContractActionMultipleOpenEndedContracts()
    {
        $values = [
            'user_id' => '3',
            'start' =>  "'2020-04-01'",
            'hours_0' => '1',
            'hours_1' => '2',
            'hours_2' => '3',
            'hours_3' => '4',
            'hours_4' => '5',
            'hours_5' => '5',
            'hours_6' => '5',
        ];

        $this->queryBuilder
            ->insert('contracts')
            ->values($values)
            ->execute();

        $parameter = [
            'user_id' => '3', //req
            'start' => '2020-08-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(406);
        $this->assertMessage('Für den Nutzer besteht mehr als ein unbefristeter Vertrag.');
    }

    public function testSaveContractActionDevNotAllowed()
    {
        $this->setInitialDbState('contracts');
        $this->logInSession('developer');
        $parameter = [
            'user_id' => '1', //req
            'start' => '2019-11-01', //req
            'hours_0' => 1,
            'hours_1' => 2,
            'hours_2' => 3,
            'hours_3' => 4.3,
            'hours_4' => 5,
            'hours_5' => 6,
            'hours_6' => 7,
        ];
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('contracts');
    }

    public function testUpdateContract()
    {
        $parameter = [
            'id' => 1,
            'user_id' => '3', //req
            'start' => '1000-01-01', //req
            'hours_0' => 0,
            'hours_1' => 0,
            'hours_2' => 0,
            'hours_3' => 0,
            'hours_4' => 0,
            'hours_5' => 0,
            'hours_6' => 0,
        ];

        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([1]);
        // validate updated contract in db
        $this->queryBuilder->select('*')
            ->from('contracts')->where('id = ?')
            ->setParameter(0, 1);
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = [
            [
                'user_id' => 3,
                'start' => '1000-01-01',
                'hours_0' => 0,
                'hours_1' => 0,
                'hours_2' => 0,
                'hours_3' => 0,
                'hours_4' => 0,
                'hours_5' => 0,
                'hours_6' => 0,
            ]
        ];
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testCreateContractUserNotExist()
    {
        $parameter = [
            'user_id' => '42', //req
            'start' => '1000-01-01', //req
            'hours_0' => 0,
            'hours_1' => 0,
            'hours_2' => 0,
            'hours_3' => 0,
            'hours_4' => 0,
            'hours_5' => 0,
            'hours_6' => 0,
        ];
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(406);
        $this->assertMessage('Bitte geben Sie einen gültigen Benutzer an.');
    }

    public function testCreateContractNoEntry()
    {
        $parameter = [
            'id' => '100',
            'user_id' => '1', //req
            'start' => '1000-01-01', //req
            'hours_0' => 0,
            'hours_1' => 0,
            'hours_2' => 0,
            'hours_3' => 0,
            'hours_4' => 0,
            'hours_5' => 0,
            'hours_6' => 0,
        ];
        $expectedJson = ['message' => 'No entry for id.'];
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(404);
        $this->assertJsonStructure($expectedJson);
    }

    public function testCreateContractInvalidStartDate()
    {
        $parameter = [
            'user_id' => '1', //req
            'start' => 'test', //req
            'hours_0' => 0,
            'hours_1' => 0,
            'hours_2' => 0,
            'hours_3' => 0,
            'hours_4' => 0,
            'hours_5' => 0,
            'hours_6' => 0,
        ];
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(406);
        $this->assertMessage('Bitte geben Sie einen gültigen Vertragsbeginn an.');
    }

    public function testCreateContractGreaterStartThenEnd()
    {
        $parameter = [
            'user_id' => '1', //req
            'start' => '1000-01-01', //req
            'end' => '0900-01-01',
            'hours_0' => 0,
            'hours_1' => 0,
            'hours_2' => 0,
            'hours_3' => 0,
            'hours_4' => 0,
            'hours_5' => 0,
            'hours_6' => 0,
        ];
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(406);
        $this->assertMessage('Das Vertragsende muss nach dem Vertragsbeginn liegen.');
    }

    public function testUpdateContractDevNotAllowed()
    {
        $this->setInitialDbState('contracts');
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'user_id' => '2', //req
            'start' => '1000-01-01', //req
            'hours_0' => 0,
            'hours_1' => 0,
            'hours_2' => 0,
            'hours_3' => 0,
            'hours_4' => 0,
            'hours_5' => 0,
            'hours_6' => 0,
        ];
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('contracts');
    }

    public function testDeleteContractAction()
    {
        $parameter = ['id' => 1,];
        $expectedJson1 = [
            'success' => true,
        ];
        $this->client->request('POST', '/contract/delete', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson1);
        //  second delete
        $expectedJson2 = [
            'message' => 'Der Datensatz konnte nicht enfernt werden! ',
        ];
        $this->client->request('POST', '/contract/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeleteContractActionDevNotAllowed()
    {
        $this->setInitialDbState('contracts');
        $this->logInSession('developer');
        $parameter = ['id' => 1,];
        $this->client->request('POST', '/contract/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('contracts');
    }

    public function testGetContractAction()
    {
        $expectedJson = [
            [
                'contract' => [
                    'id' => 3,
                    'user_id' => 2,
                    'start' => '1020-01-01',
                    'end' => '2020-01-01',
                    'hours_0' => 1,
                    'hours_1' => 1,
                    'hours_2' => 1,
                    'hours_3' => 1,
                    'hours_4' => 1,
                    'hours_5' => 1,
                    'hours_6' => 1,
                ],
            ],
            [
                'contract' => [
                    'id' => 1,
                    'user_id' => 1,
                    'start' => '2020-01-01',
                    'end' => '2020-01-31',
                    'hours_0' => 0,
                    'hours_1' => 1,
                    'hours_2' => 2,
                    'hours_3' => 3,
                    'hours_4' => 4,
                    'hours_5' => 5,
                    'hours_6' => 0,
                ],
            ],
            [
                'contract' => [
                    'id' => 2,
                    'user_id' => 1,
                    'start' => '2020-02-01',
                    'hours_0' => 0,
                    'hours_1' => 1.1,
                    'hours_2' => 2.2,
                    'hours_3' => 3.3,
                    'hours_4' => 4.4,
                    'hours_5' => 5.5,
                    'hours_6' => 0.5,
                ],
            ],
            [
                'contract' => [
                    'id' => 4,
                    'user_id' => 3,
                    'start' => '0700-01-01',
                    'hours_0' => 1,
                    'hours_1' => 2,
                    'hours_2' => 3,
                    'hours_3' => 4,
                    'hours_4' => 5,
                    'hours_5' => 5,
                    'hours_6' => 5,
                ],
            ],
        ];

        $this->client->request('GET', '/contracts');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }
}
