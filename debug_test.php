<?php
require_once 'tests/bootstrap.php';
use Tests\AbstractWebTestCase;

class DebugTest extends AbstractWebTestCase 
{
    public function test() {
        $parameter = ['maxResults=2', 'page=0'];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/interpretation/allEntries?' . implode('&', $parameter));
        $response = json_decode($this->client->getResponse()->getContent(), true);
        echo "Page 0 actual data: " . json_encode($response['data']) . "\n";
        
        $parameter = ['maxResults=2', 'page=1'];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/interpretation/allEntries?' . implode('&', $parameter));
        $response = json_decode($this->client->getResponse()->getContent(), true);
        echo "Page 1 actual data: " . json_encode($response['data']) . "\n";
    }
}
