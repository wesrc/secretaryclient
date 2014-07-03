<?php
/**
 * Permission is hereby granted, free of charge, to any person obtaining
 * a copy of this software and associated documentation files (the
 * "Software"), to deal in the Software without restriction, including
 * without limitation the rights to use, copy, modify, merge, publish,
 * distribute, sublicense, and/or sell copies of the Software, and to
 * permit persons to whom the Software is furnished to do so, subject to
 * the following conditions:
 *
 * The above copyright notice and this permission notice shall be included
 * in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
 * OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
 * THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 *
 * PHP Version 5
 *
 * @category Command
 * @package  SecretaryClient\Command
 * @author   Michael Scholl <michael@wesrc.com>
 * @license  http://www.opensource.org/licenses/mit-license.html MIT License
 * @link     https://github.com/wesrc/secretary
 */

namespace SecretaryClient\Command;

use SecretaryClient\Helper;
use SecretaryClient\Client;
use SecretaryCrypt\Crypt;
use Symfony\Component\Console;

/**
 * Create Command
 */
class Create extends Base
{
    /**
     * @var Crypt
     */
    private $cryptService;

    /**
     * @param Crypt $cryptService
     */
    public function __construct(Crypt $cryptService)
    {
        $this->cryptService = $cryptService;
        parent::__construct();
    }

    /**
     * Configure create command
     */
    protected function configure()
    {
        $this
            ->setName('create')
            ->setDescription('Create a new note')
            ->addOption(
               'file',
               null,
                Console\Input\InputOption::VALUE_OPTIONAL,
               'If set, file (path) is used to fetch content of note'
            )
        ;
    }

    /**
     * @param Console\Input\InputInterface $input
     * @param Console\Output\OutputInterface $output
     * @return void
     */
    protected function execute(Console\Input\InputInterface $input, Console\Output\OutputInterface $output)
    {
        $this->checkConfiguration($output);
        $this->input = $input;
        $this->output = $output;

        try {
            /** @var Helper\EditorHelper $editorHelper */
            $editorHelper = $this->getHelperSet()->get('editor');

            $private = $this->getPrivateValue();
            $title = $this->getTitleValue();
            $content = $editorHelper->useEditor($output);

            if ($private == 0) {
                $note = $this->createGroupNote($title, $content);
            } else {
                $note = $this->createPrivateNote($title, $content);
            }
            unset($content);
        } catch(Client\Exception $e) {
            $output->writeln($e->getMessage());
            return;
        }

        $output->writeln('Note with ID: ' . $note['id'] . ' was created.');

        return;
    }

    /**
     * @param string $title
     * @param string $content
     * @return array
     */
    private function createGroupNote($title, $content)
    {
        $groupData = $this->getGroupValue();
        $selectedGroup = $groupData['group'];
        $groupUsers = $groupData['groupUsers'];

        $userData = $this->getUserOfGroupValue($groupUsers, $selectedGroup);
        $selectedUser = $userData['user'];
        $selectedUserKey = $this->getUserPublicKey($selectedUser);

        $cryptUserKeys = [
            $selectedUser => $selectedUserKey,
            $this->config['userId'] => $this->config['publicKey'],
        ];

        $contentWithKey = $this->cryptService->encryptForMultipleKeys($content, $cryptUserKeys);
        unset($content);

        /** @var Client\Note $client */
        $client = $this->getClient('note', $this->config);
        $note = $client->createGroupNote(
            $title,
            $contentWithKey['content'],
            $selectedGroup
        );
        $client->checkForError($client);

        /** @var Client\User2Note $client */
        $client = $this->getClient('user2note', $this->config);
        $i = 0;
        foreach ($cryptUserKeys as $userId => $key) {
            $owner = false;
            $readPermission = true;
            $writePermission = false;
            if ($userId == $this->config['userId']) {
                $owner = true;
                $writePermission = true;
            }
            $client->createUser2Note(
                $userId,
                $note['id'],
                $contentWithKey['ekeys'][$i],
                $owner,
                $readPermission,
                $writePermission
            );
            $client->checkForError($client);
            $i++;
        }

        return $note;
    }

    /**
     * @param string $title
     * @param string $content
     * @return array
     */
    private function createPrivateNote($title, $content)
    {
        $contentWithKey = $this->cryptService->encryptForSingleKey($content, $this->config['publicKey']);
        unset($content);

        /** @var Client\Note $client */
        $client = $this->getClient('note', $this->config);
        $note = $client->createPrivateNote(
            $title,
            $contentWithKey['content']
        );
        $client->checkForError($client);

        /** @var Client\User2Note $client */
        $client = $this->getClient('user2note', $this->config);
        $client->createUser2Note(
            $this->config['userId'],
            $note['id'],
            $contentWithKey['ekey'],
            true,
            true,
            true
        );
        $client->checkForError($client);

        return $note;
    }

    /**
     * @param array $collection
     * @return array
     */
    private function extractGroupNames(array $collection)
    {
        $groups = [];
        foreach ($collection as $group) {
            $groups[$group['id']] = $group['name'];
        }

        return $groups;
    }

    /**
     * @param array $collection
     * @return array
     */
    private function extractGroupUsers(array $collection)
    {
        $groupUsers = [];
        foreach ($collection as $group) {
            $groupUsers[$group['id']] = $group['users'];
        }

        return $groupUsers;
    }

    /**
     * @param array $collection
     * @return array
     */
    private function extractUserNames(array $collection)
    {
        $users = [];
        foreach ($collection as $user) {
            $users[$user['id']] = $user['email'];
        }

        return $users;
    }

    /**
     * @return int
     */
    private function getPrivateValue()
    {
        $question = new Console\Question\ChoiceQuestion(
            'Private or Group note?',
            array('private', 'group'),
            0
        );
        $question->setErrorMessage('Answer %s is invalid.');
        $kind = $this->askQuestion($question);

        $private = 1;
        if($kind == 'group') {
            $private = 0;
        }

        return $private;
    }

    /**
     * @return string
     */
    private function getTitleValue()
    {
        $question = new Console\Question\Question('Title of note: ');
        $question->setValidator(function ($answer) {
            if (empty($answer)) {
                throw new \InvalidArgumentException('You need to provide a title value');
            }
            return $answer;
        });

        return $this->askQuestion($question);
    }

    /**
     * @return array with values `group` and `groupUsers`
     */
    private function getGroupValue()
    {
        /** @var Client\Group $groupClient */
        $groupClient = $this->getClient('group', $this->config);
        $groupPaginator = $groupClient->getUserGroups();
        $groupClient->checkForError($groupClient);

        $groups = $this->extractGroupNames($groupPaginator['_embedded']['group']);
        $groupUsers = $this->extractGroupUsers($groupPaginator['_embedded']['group']);

        /** @var Console\Helper\QuestionHelper $helper */
        $question = new Console\Question\ChoiceQuestion('Select a group:', $groups);
        $selectedGroupName = $this->askQuestion($question);
        $flippedGroups = array_flip($groups);

        return [
            'group' => $flippedGroups[$selectedGroupName],
            'groupUsers' => $groupUsers,
        ];
    }

    /**
     * @param array $groupUsers
     * @param int $selectedGroup
     * @return array with values `group` and `groupUsers`
     */
    private function getUserOfGroupValue($groupUsers, $selectedGroup)
    {
        /** @var Client\User $userClient */
        $userClient = $this->getClient('user', $this->config);
        $groupUsersData = $userClient->getUsers($groupUsers[$selectedGroup]);
        $userClient->checkForError($userClient);

        $users = $this->extractUserNames($groupUsersData);
        unset($users[$this->config['userId']]);
        $question = new Console\Question\ChoiceQuestion('Select a user:', $users);
        $selectedUserName = $this->askQuestion($question);
        $flippedUsers = array_flip($users);

        return [
            'user' => $flippedUsers[$selectedUserName],
            'users' => $users,
        ];
    }

    /**
     * @param int $userId
     * @return string
     */
    private function getUserPublicKey($userId)
    {
        /** @var Client\Key $keyClient */
        $keyClient = $this->getClient('key', $this->config);
        $userKey = $keyClient->getById($userId);
        $keyClient->checkForError($keyClient);

        return $userKey;
    }
}
