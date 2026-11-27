import { useFormContext } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { isHttpUrl, SOCIAL_NETWORKS, SocialAction, SocialConfig, SocialNetwork } from '@/lib/schema/campaign.schema';
import { Button } from '@/components/ui/button';
import { AMOUNT_INPUT_WIDTH } from '@/components/ui/custom/amount-inputs';
import { Field, FieldContent, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { CampaignFormValues } from './campaign-editor.form';
import { socialShareUrl } from './social-share-url';

/** Brand names, the same in every language; mirrors SocialHandler::NETWORKS. */
const NETWORK_NAMES: Record<SocialNetwork, string> = {
  facebook: 'Facebook',
  instagram: 'Instagram',
  x: 'X',
  tiktok: 'TikTok',
  youtube: 'YouTube',
  linkedin: 'LinkedIn',
  pinterest: 'Pinterest',
};

/** Reward fields for a social campaign: one network action, its link, and the points it pays once per customer. */
export function CampaignEditorEarnSocial() {
  const { watch, setValue } = useFormContext<CampaignFormValues>();
  const config = watch('config') as SocialConfig;
  const network = config.network ?? 'instagram';
  const url = config.url ?? '';
  const shareUrl = config.action === 'share' ? socialShareUrl(network, window.yayLoyaltyMeta.wpMeta.homeUrl) : null;

  function patchConfig(patch: Partial<SocialConfig>) {
    setValue('config', { ...config, ...patch }, { shouldDirty: true, shouldValidate: true });
  }

  return (
    <>
      <Field>
        <FieldLabel htmlFor="reward-social-network">{__('Network', 'yayloyalty')}</FieldLabel>
        <Select value={network} onValueChange={(next) => patchConfig({ network: next as SocialNetwork })}>
          <SelectTrigger id="reward-social-network" className="w-48">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {SOCIAL_NETWORKS.map((key) => (
              <SelectItem key={key} value={key}>
                {NETWORK_NAMES[key]}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </Field>

      <Field>
        <FieldLabel>{__('Action', 'yayloyalty')}</FieldLabel>
        <FieldContent>
          <Segmented
            className="w-max"
            value={config.action ?? 'follow'}
            onValueChange={(next) => next && patchConfig({ action: next as SocialAction })}
          >
            <SegmentedItem value="follow">{__('Follow', 'yayloyalty')}</SegmentedItem>
            <SegmentedItem value="share">{__('Share', 'yayloyalty')}</SegmentedItem>
          </Segmented>
        </FieldContent>
      </Field>

      <Field>
        <FieldLabel htmlFor="reward-social-url">{__('Link', 'yayloyalty')}</FieldLabel>
        <FieldContent>
          <Input
            id="reward-social-url"
            type="url"
            placeholder="https://"
            value={url}
            aria-invalid={!isHttpUrl(url)}
            onChange={(event) => patchConfig({ url: event.target.value.trim() })}
          />
          {shareUrl && (
            <Button
              type="button"
              variant="link"
              size="sm"
              className="self-start px-0"
              onClick={() => patchConfig({ url: shareUrl })}
            >
              {__('Use a share link for my store', 'yayloyalty')}
            </Button>
          )}
          {!isHttpUrl(url) ? (
            <FieldError>{__('Enter a full link that starts with https://', 'yayloyalty')}</FieldError>
          ) : (
            <FieldDescription>
              {__(
                'Where the customer goes after clicking. Points are paid on the click, once per customer.',
                'yayloyalty',
              )}
            </FieldDescription>
          )}
        </FieldContent>
      </Field>

      <Field>
        <FieldLabel htmlFor="reward-social-points">{__('Award', 'yayloyalty')}</FieldLabel>
        <FieldContent>
          <NumberInputRoot
            size="small"
            className={AMOUNT_INPUT_WIDTH}
            min={0}
            value={config.points ?? 0}
            onValueChange={(points) => patchConfig({ points })}
          >
            <NumberInputInput id="reward-social-points" />
            <NumberInputUnit unit={__('points', 'yayloyalty')} />
          </NumberInputRoot>
        </FieldContent>
      </Field>
    </>
  );
}
