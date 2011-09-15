<?php if (!defined('APPLICATION')) exit(); ?>

<h1><?php echo $this->Data('Title'); ?></h1>

<?php
echo $this->Form->Open();
echo $this->Form->Errors();
?>

<div class="Info">
	<?php echo T('VkAuth allows users to sign in using their Vkontakte account.'); ?>
</div>
<div class="Configuration">
	<div class="ConfigurationForm">
		<ul>
			<li>
				<?php
					echo $this->Form->Label('Application ID', 'ApplicationID');
					echo $this->Form->TextBox('ApplicationID');
				?>
			</li>
			<li>
				<?php
					echo $this->Form->Label('Secure Key', 'Secret');
					echo $this->Form->TextBox('Secret');
				?>
			</li>
		</ul>
		<?php 
			echo $this->Form->Button('Save', array('class' => 'Button SliceSubmit')); 
		?>
	</div>
	<div class="Info ConfigurationHelp">
		<p>In order to set up VkAuth, you must create an application at: <a href="http://vkontakte.ru/editapp?act=create">http://vkontakte.ru/editapp?act=create</a></p>
		<p>
			When you create the Vkontakte application, you can choose what to enter in most fields.<br/>
			But make sure you enter the following value in the "Home domain" field:
			<input type="text" class="CopyInput" value="<?php echo Gdn::Request()->Host(); ?>" />
		</p>
		<p><?php echo Anchor(Img('/plugins/VkAuth/design/vk-help.png', array('style' => 'max-width:100%')), '/plugins/VkAuth/design/vk-help.png', array('target' => '_blank')); ?></p>
		<p>Once your application has been set up, you must copy the "Application ID" and "Secure Key" into the form on this page and click save.</p>
	</div>
	
</div>
<?php 
echo $this->Form->Close();
?>