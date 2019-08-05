<h2>Potential identity change detected</h2>
<p>When changing first name, last name or birth date, the contact's identity could be compromised. Please process this change manually, and with caution:</p>
<table>
  <thead>
    <tr>
      <td><b>Attribute<b></td>
      {if $contact}
        <td><b>Recorded Value</b></td>
      {/if}
      <td><b>Submitted Value</b></td>
    </tr>
  </thead>
  <tbody>
    {foreach from=$update key=attribute item=value}
      <tr>
        <td>
          {if $attribute eq 'first_name'}
            First Name
          {elseif $attribute eq 'last_name'}
            Last Name
          {elseif $attribute eq 'current_employer'}
            Current Employer
          {elseif $attribute eq 'prefix_id'}
            Prefix
          {elseif $attribute eq 'birth_date'}
            Birth Date
          {elseif $attribute eq 'formal_title'}
            Formal Title
          {else}
            Birth Year
          {/if}
        </td>
        {if $contact}
          <td>
            {if $attribute eq 'prefix_id'}
              {$contact.individual_prefix|escape}
            {else}
              {$contact.$attribute|escape}
            {/if}</td>
        {/if}
        <td {if $attribute|in_array:$diff} style="color: red;"{/if}>
          {$value|escape}
        </td>
      </tr>
    {/foreach}
  </tbody>
</table>