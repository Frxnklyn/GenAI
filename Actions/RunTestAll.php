<?php
namespace axenox\GenAI\Actions;

use axenox\GenAI\Common\AiPrompt;
use axenox\GenAI\Actions\RunTest;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\CommonLogic\DataSheets\DataCollector;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\DataTypes\ComparatorDataType;


/**
 * Select from selected AI Agent all TestCases and run the Test Cases
 * 
 * TODO add docs
 */
class RunTestAll extends RunTest
{



    protected function init()
    {
        parent::init();
        $this->setInputRowsMin(1); 
    }

    

    /**
     * @inheritDoc
     */
    protected function performDeferred(TaskInterface $task = null): \Generator
    {

        $inputSheet = $task->getInputData();
        $collector = new DataCollector(MetaObjectFactory::createFromString($this->getWorkbench(), 'axenox.GenAI.AI_TEST_CASE'));
        $collector->addAttributeAlias('UID');
        $collector->collectFrom($inputSheet);
        $agentSheet = $collector->getRequiredData();

        $testCasesSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getworkbench(), 'axenox.GenAI.AI_TEST_CASE');
        $testCasesSheet->getColumns()->addMultiple([
            'AI_AGENT',
            'NAME',
            'UID'
        ]);
        $testCasesSheet->getFilters()->addConditionFromString('AI_AGENT',$agentSheet->getCellValue('UID',0),ComparatorDataType::EQUALS);
        $testCasesSheet->dataRead();

        yield 'Found ' . $testCasesSheet->countRows() . ' Testcases ...';

        foreach($testCasesSheet->getRows() as $rowNr => $row){
            $run = new RunTest($this->getApp());
            $testSheet = $inputSheet->copy()->removeRows();
            $testSheet->addRow($row);
            $run->setInputSheet($testSheet);
            $run->runTestCase();
            yield 'TestCaseNumber: ' ($rowNr + 1) . 'finished.';
        }

        

        
        yield 'Finished';
    }

   
    
  





}