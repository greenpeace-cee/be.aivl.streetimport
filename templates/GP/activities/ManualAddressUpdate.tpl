<h2>{$title}</h2>
{if !empty($subtitle)}
  <h3 class="crm-error">{$subtitle}</h3>
{/if}
<table>
  <thead>
    <tr>
      <td></td>
      <td>New Address</td>
      {if $old_address}
      <td>Old Address</td>
      {/if}
    </tr>
  </thead>
  <tbody>
    {foreach from=$fields item=field}
      {if !empty($address.$field) or !empty($old_address.$field)}
      <tr>
        <td>{$field}</td>
        <td>{$address.$field|default:''}</td>
        {if $old_address}
        <td>{$old_address.$field|default:''}</td>
        {/if}
      </tr>
      {/if}
    {/foreach}
  </tbody>
</table>
