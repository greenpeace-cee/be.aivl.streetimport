<h2>{$title}</h2>
<table>
  <tr>
    <td>{ts}Message{/ts}</td>
    <td>{$message}</td>
  </tr>
  <tr>
    <td>{ts}File Reference{/ts}</td>
    <td>{$id}</td>
  </tr>
</table>
<h3>Record</h3>
<table>
  <thead>
  <tr>
    <th>{ts}Field{/ts}</th>
    <th>{ts}Value{/ts}</th>
  </tr>
  </thead>
  <tbody>
  {foreach from=$record key=field item=value}
    <tr>
      <td>{$field|htmlentities}</td>
      <td>{$value|htmlentities}</td>
    </tr>
  {/foreach}
  </tbody>
</table>
