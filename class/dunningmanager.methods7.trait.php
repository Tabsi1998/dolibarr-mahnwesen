<?php
/* Wording the invoice tab and the composer share (#56). */
trait DunningManagerMethods7
{
    /**
     * The next step as one phrase: "2. Mahnung (zeitlich wäre bereits die 3. Mahnung erreicht)".
     *
     * @param int $calculatedLevel Stage reached by the calendar
     * @param int $requiredLevel Next stage the workflow allows
     * @param int $futureLevel Next stage not reached yet
     * @return string HTML
     */
    public function describeNextStep($calculatedLevel, $requiredLevel, $futureLevel)
    {
        global $langs;
        if ((int) $requiredLevel > 0) {
            $html = '<strong>'.$langs->trans($this->getStageLabelKey((int) $requiredLevel)).'</strong>';
            if ((int) $calculatedLevel > (int) $requiredLevel) {
                $html .= ' <span class="opacitymedium">'.$langs->trans('MahnwesenNextStepBehind', $langs->trans($this->getStageLabelKey((int) $calculatedLevel))).'</span>';
            }
            return $html;
        }
        if ((int) $futureLevel > 0) {
            return '<span class="opacitymedium">'.$langs->trans('MahnwesenNoDueWorkflowStage').'</span>';
        }
        return '<span class="badge badge-status4">'.$langs->trans('MahnwesenWorkflowComplete').'</span>';
    }

    /**
     * The amount to pay, with its parts only when a fee applies.
     *
     * @param array $breakdown From getAmountBreakdown()
     * @return string HTML
     */
    public function describeAmountDue($breakdown)
    {
        global $langs, $conf;
        $html = '<strong>'.price((float) $breakdown['total'], 0, $langs, 1, -1, -1, $conf->currency).'</strong>';
        if ((float) $breakdown['fee'] > 0.000001) {
            $html .= ' <span class="opacitymedium">'.$langs->trans('MahnwesenAmountParts',
                price((float) $breakdown['invoice'], 0, $langs, 1, -1, -1, $conf->currency),
                price((float) $breakdown['fee'], 0, $langs, 1, -1, -1, $conf->currency)).'</span>';
        }
        return $html;
    }
}
