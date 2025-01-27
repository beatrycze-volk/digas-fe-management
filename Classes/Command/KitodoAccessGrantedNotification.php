<?php

namespace Slub\DigasFeManagement\Command;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2022 SLUB Dresden <typo3@slub-dresden.de>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use Slub\DigasFeManagement\Domain\Model\Access;
use Slub\DigasFeManagement\Domain\Model\User;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility as ExtbaseLocalizationUtility;

/**
 * Class KitodoAccessGrantedNotification
 */
class KitodoAccessGrantedNotification extends DigasBaseCommand
{
    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
    }

    /**
     * Configure the command by defining the name, options and arguments
     */
    protected function configure()
    {
        $this->setDescription('[DiGA.Sax FE Management] Notify fe_users about access granted for kitodo documents.')
            ->setHelp(
                'This command informs fe_users with granted kitodo document access.'
            );
    }

    /**
     * Executes the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Initialize IO
        parent::execute($input, $output);

        $this->io->text('Get fe_users with document requests.');
        // get fe users with requests for access loop
        $grantedAccessUsers = $this->AccessRepository->findAccessGrantedUsers();
        $this->io->text(count($grantedAccessUsers) . ' fe_users with requests documents were found.');

        if (!empty($grantedAccessUsers)) {
            foreach ($grantedAccessUsers as $accessUser) {
                /** @var User $feUser */
                $feUser = $this->UserRepository->findByUid($accessUser->getFeUser());
                $grantedAccessEntries = $this->AccessRepository->findAccessGrantedEntriesByUser($accessUser->getFeUser());

                if (!empty($grantedAccessEntries)) {
                    $this->io->text(sprintf('Notify fe_user (UID: %s) with %s document requests.', $accessUser->getFeUser(), count($grantedAccessEntries)));

                    $this->notifyUser($feUser, $grantedAccessEntries);
                    $this->persistenceManager->persistAll();
                }
            }
        }

        $this->io->success('Task finished successfully.');

        return 0;
    }

    /**
     * Update access model object and set accessGrantedNotification
     *
     * @param Access $accessEntry
     * @param int $notificationTimestamp
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\IllegalObjectTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\UnknownObjectException
     */
    protected function updateAccessEntry(Access $accessEntry, int $notificationTimestamp)
    {
        // update access entry with notification time
        $accessEntry->setAccessGrantedNotification($notificationTimestamp);
        $accessEntry->setInformUser(false);
        $this->AccessRepository->update($accessEntry);
    }

    /**
     * Send email to fe_users with granted access documents
     *
     * @param User $feUser
     * @param array $documentsList
     */
    protected function sendNotificationEmail(User $feUser, array $documentsList)
    {
        $this->initUserLocal($feUser);
        $userEmail = $feUser->getEmail();
        $userFullName = $feUser->getFullName();
        if (!GeneralUtility::validEmail($userEmail)) {
            $this->io->warning(sprintf('[DiGA.Sax FE Management] Granted access notification warning to user (UID: %s) could not be sent. No valid email address.', $feUser->getUid()));
            return;
        }
        $email = GeneralUtility::makeInstance(MailMessage::class);

        $textEmail = $this->generateNotificationEmail(
            $documentsList,
            'EXT:digas_fe_management/Resources/Private/Templates/Email/Text/KitodoAccessGrantedNotification.html'
        );
        $htmlEmail = $this->generateNotificationEmail(
            $documentsList,
            'EXT:digas_fe_management/Resources/Private/Templates/Email/Html/KitodoAccessGrantedNotification.html',
            'html'
        );

        $emailSubject = ExtbaseLocalizationUtility::translate('kitodoAccessGrantedNotification.email.subject', 'DigasFeManagement');

        // Prepare and send the message
        $email->setSubject($emailSubject)
            ->setFrom([
                $this->settings['adminEmail'] => $this->settings['adminName']
            ])
            ->setTo([
                $userEmail => $userFullName
            ])
            ->text($textEmail)
            ->html($htmlEmail)
            ->send();
    }
}
